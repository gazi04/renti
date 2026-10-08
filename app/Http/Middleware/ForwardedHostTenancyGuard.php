<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Stancl\Tenancy\Database\Models\Domain;
use Symfony\Component\HttpFoundation\Response;

/**
 * Diagnoses — and, behind an off-by-default flag, recovers from — a load balancer
 * that rewrites the Host header.
 *
 * Tenants are resolved from $request->getHost() by InitializeTenancyByDomain, and
 * routes/tenant.php carries no Route::domain() constraint. If the load balancer
 * rewrites Host to its own hostname and passes the real one only in
 * X-Forwarded-Host, nothing resolves and EVERY tenant subdomain 404s. That 404 is
 * indistinguishable from an ordinary unknown-subdomain 404, so a total outage
 * arrives with no signal at all. Laravel Cloud does not document which it does,
 * and it cannot be established from a dev machine — hence a detector that is
 * always on and a remedy that is one env var away.
 *
 * X-Forwarded-Host is NOT trusted, here or in bootstrap/app.php's trustProxies()
 * call, and must never be. This middleware validates the header against the
 * `domains` table instead; it never takes the client's word for anything.
 *
 * # Why the recovery path is safe
 *
 * The 2026-09-03 red-team finding was that X-Forwarded-Host could name any tenant
 * and be served that tenant's storefront ON THE CENTRAL ORIGIN — confirmed as a
 * 200 before the fix. That is worse than an ordinary host spoof because
 * SESSION_DOMAIN is scoped to the parent domain (operator impersonation needs the
 * cookie to survive the admin -> subdomain redirect), so tenant-controlled content
 * rendered on the admin origin crosses a session-cookie boundary.
 *
 * Condition 3 below makes that outcome unreachable: when getHost() is a central or
 * admin domain this returns before it has even queried the database. Condition 4
 * closes the neighbouring case — a request that already resolves to tenant A can
 * never be re-pointed at tenant B.
 *
 * What is left is: Host resolves to NOTHING, and the forwarded host is a real
 * tenant domain. Reaching that state means already talking to the origin directly
 * (an off-LB port, SSRF) — and anyone in that position can simply send `Host: T`
 * and be served tenant T with no header at all. The recovery path is not a new
 * door; it is the same door reached by a longer corridor.
 */
class ForwardedHostTenancyGuard
{
    /** A header longer than this is not a hostname; drop it unread. */
    private const int MAX_HEADER_LENGTH = 255;

    /** One alert per tenant domain per hour: loud enough for an outage, quiet enough for a prober. */
    private const int ALERT_TTL_MINUTES = 60;

    /** Dotted DNS labels. Also rejects bracketed IPv6 literals, which can never be a tenant domain. */
    private const string HOSTNAME = '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $forwarded = $this->forwardedHost($request);

        // (1) No usable header. The overwhelming majority of requests, and every
        //     request that never passes a proxy at all — so healthy traffic pays
        //     nothing for this middleware being global.
        if ($forwarded === null) {
            return $next($request);
        }

        $host = $request->getHost();

        // (2) The load balancer preserved Host: the healthy case. This is also
        //     what happens if HEADER_X_FORWARDED_HOST is ever added back to the
        //     trusted set — getHost() would already BE the forwarded host, so this
        //     middleware goes inert rather than racing the framework.
        if ($forwarded === $host) {
            return $next($request);
        }

        // (3) Never touch a central or admin origin. This is the security
        //     property; see the class docblock.
        if ($this->isCentralDomain($host)) {
            return $next($request);
        }

        try {
            $known = $this->knownDomains($host, $forwarded);
        } catch (QueryException) {
            // Diagnostics must never be why a request fails. This runs globally,
            // so without the catch /up would transitively depend on the database
            // whenever a differing X-Forwarded-Host arrives, and a database blip
            // would pull healthy instances out of the load balancer.
            return $next($request);
        }

        // (4) Host already resolves to a tenant, so tenancy is working. A
        //     forwarded host naming a *different* tenant is the original
        //     vulnerability, and is ignored here without comment.
        if (in_array($host, $known, strict: true)) {
            return $next($request);
        }

        // (5) The forwarded host is not a real tenant domain either — ordinary
        //     unknown-subdomain probing. No log line, no behaviour change, so this
        //     cannot be turned into a log-flood primitive.
        if (! in_array($forwarded, $known, strict: true)) {
            return $next($request);
        }

        $recovering = config()->boolean('security.forwarded_host_recovery');

        $this->report($host, $forwarded, $recovering);

        if ($recovering) {
            $this->rewriteHost($request, $forwarded);
        }

        return $next($request);
    }

    /**
     * The first element of X-Forwarded-Host, normalised to a bare lowercase hostname.
     *
     * A chain of proxies appends to this header, so it can be a comma-separated
     * list; Symfony itself takes element [0] when the header is trusted, because
     * the earliest proxy is the one that saw the original Host. Matching that
     * means this middleware and the framework can never disagree about which
     * element "the" forwarded host is.
     *
     * The syntactic gate runs before any query and before anything is written back
     * into the headers bag: a value Symfony would reject with a
     * SuspiciousOperationException must never be installed as HOST.
     */
    private function forwardedHost(Request $request): ?string
    {
        $raw = $request->headers->get('X-Forwarded-Host');

        if (! is_string($raw) || $raw === '' || strlen($raw) > self::MAX_HEADER_LENGTH) {
            return null;
        }

        $first = trim(explode(',', $raw, 2)[0]);

        // Same normalisation Symfony applies to Host: drop a trailing :port, lowercase.
        // The domains table stores bare hostnames.
        $host = strtolower((string) preg_replace('/:\d+$/', '', $first));

        return $host !== '' && preg_match(self::HOSTNAME, $host) === 1 ? $host : null;
    }

    private function isCentralDomain(string $host): bool
    {
        /** @var list<string> $central */
        $central = config()->array('tenancy.central_domains', []);

        // getHost() is always lowercase; config entries come from env and may not be.
        return in_array($host, array_map(strtolower(...), $central), strict: true);
    }

    /**
     * Which of these two hosts exist in the `domains` table.
     *
     * Conditions 4 and 5 are both answered by one indexed whereIn, so the
     * expensive branch costs a single query rather than two.
     *
     * @return list<string>
     */
    private function knownDomains(string $primary, string $forwarded): array
    {
        /** @var list<string> $domains */
        $domains = Domain::query()->whereIn('domain', [$primary, $forwarded])
            ->pluck('domain')
            ->all();

        return $domains;
    }

    /**
     * Point the request at the validated host.
     *
     * Request::getHost() recomputes from the headers bag on every call — there is
     * no memoisation — so setting HOST is enough for InitializeTenancyByDomain to
     * see it. The server bag is set alongside so nothing reading HTTP_HOST
     * directly can disagree. getRequestUri()/getPathInfo() are cached but contain
     * no host, so there is nothing to invalidate.
     *
     * No port is written back: $host is a bare hostname out of the domains table,
     * and getPort() then falls back to the still-trusted X-Forwarded-Port. Writing
     * a client-supplied port would let the header influence generated URLs, which
     * is exactly what this middleware exists to prevent.
     */
    private function rewriteHost(Request $request, string $host): void
    {
        $request->headers->set('HOST', $host);
        $request->server->set('HTTP_HOST', $host);
    }

    /**
     * Alert once per tenant domain per window.
     *
     * Deduped through the cache rather than a static array. The static-array
     * precedent in AiCostEstimator is right for a queue worker handling many jobs
     * in one process, but under FPM every request is a fresh process and this runs
     * at most once per request, so a static would throttle nothing. Cache::add()
     * is atomic and shared across workers.
     *
     * The key derives from the FORWARDED host, never the primary one: the primary
     * host is attacker-controlled and unbounded, so keying on it would be a
     * cache-fill primitive. By this point the forwarded host is provably a row in
     * the domains table, so key cardinality is bounded by the tenant count.
     *
     * tenant_id is always null here — global middleware runs before tenancy
     * initialises — and is included anyway so every security log line in the app
     * carries the same context shape and can be filtered uniformly.
     */
    private function report(string $host, string $forwarded, bool $recovering): void
    {
        // The host goes into the key verbatim rather than through a digest: it has
        // already passed the HOSTNAME regex, so it is [a-z0-9.-] and safe in a
        // cache key, and hashing it would buy nothing (the tests/Arch security
        // preset rightly bans sha1 outright, and reaching for a stronger digest
        // here would be ceremony around a value that is not a secret).
        $key = 'tenancy:forwarded-host-mismatch:'.$forwarded;

        if (! Cache::add($key, true, now()->addMinutes(self::ALERT_TTL_MINUTES))) {
            return;
        }

        Log::warning('Request Host does not resolve to a tenant but X-Forwarded-Host does; the load balancer is probably rewriting Host.', [
            'tenant_id' => tenant('id'),
            'host' => $host,
            'forwarded_host' => $forwarded,
            'recovery_enabled' => $recovering,
            'hint' => $recovering
                ? 'Recovery is on and this request resolved from the validated forwarded host. Fix the load balancer to preserve the original Host header, then set FORWARDED_HOST_RECOVERY=false.'
                : 'If this is firing for every tenant subdomain, tenant resolution is broken in production. Set FORWARDED_HOST_RECOVERY=true (see config/security.php) to resolve from the validated X-Forwarded-Host without a code deploy.',
        ]);
    }
}
