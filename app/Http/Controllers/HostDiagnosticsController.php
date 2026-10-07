<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Stancl\Tenancy\Database\Models\Domain;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

/**
 * Reports what the application actually sees for Host, X-Forwarded-Host, scheme
 * and port.
 *
 * It exists for one check: on the first deploy to a new environment, confirm that the load balancer preserves the
 * original Host header. If it does not — if it rewrites Host and passes the real
 * hostname only in X-Forwarded-Host — InitializeTenancyByDomain resolves against
 * the wrong host and every tenant subdomain 404s in production.
 *
 * An Artisan command cannot answer this: there is no request, so there is no Host
 * header and getHost() would be synthesised from app.url. It has to be an HTTP
 * endpoint, and it has to answer while tenant resolution is broken — which is why
 * it is registered with no Route::domain() constraint and carries no tenancy
 * middleware.
 *
 * # What it will not tell you
 *
 * The two "does this resolve" answers are booleans. This endpoint never returns a
 * tenant id, name or subdomain, never echoes the configured central/admin domains,
 * and never dumps the header or server bags. That is what makes it safe to reach
 * on an unknown host: it cannot be used to enumerate which subdomains exist. Every
 * value it does return is either the caller's own input or trivially derivable
 * from it.
 *
 * Gated in the controller rather than by conditionally registering the route:
 * route:cache is in the deploy command list, which would freeze
 * the flag into the cached route file and make toggling it look like a no-op.
 */
class HostDiagnosticsController extends Controller
{
    /** The raw header is echoed back to its sender, but not unboundedly. */
    private const int MAX_ECHO_LENGTH = 255;

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless(config()->boolean('security.host_diagnostics_enabled'), 404);

        $host = $request->getHost();
        $forwardedRaw = $request->headers->get('X-Forwarded-Host');
        $forwarded = $this->normalize($forwardedRaw);

        /** @var list<string> $central */
        $central = config()->array('tenancy.central_domains', []);

        $candidates = $forwarded === null ? [$host] : [$host, $forwarded];

        /** @var list<string> $resolves */
        $resolves = Domain::query()->whereIn('domain', $candidates)->pluck('domain')->all();

        return response()->json([
            'host' => $host,
            'http_host' => $request->getHttpHost(),
            'forwarded_host_raw' => is_string($forwardedRaw)
                ? mb_substr($forwardedRaw, 0, self::MAX_ECHO_LENGTH)
                : null,
            'forwarded_host_normalized' => $forwarded,
            // The single fact this probe exists to establish alongside `host`.
            'x_forwarded_host_trusted' => (SymfonyRequest::getTrustedHeaderSet() & SymfonyRequest::HEADER_X_FORWARDED_HOST) !== 0,
            // Confirms X-Forwarded-Proto/Port are landing — the reason trustProxies()
            // exists at all, since signed cancel/agreement links are scheme-sensitive.
            'scheme' => $request->getScheme(),
            'is_secure' => $request->isSecure(),
            'port' => $request->getPort(),
            // The caller's own address; confirms X-Forwarded-For is landing.
            'client_ip' => $request->ip(),
            'host_resolves_to_tenant' => in_array($host, $resolves, strict: true),
            'forwarded_host_resolves_to_tenant' => $forwarded !== null && in_array($forwarded, $resolves, strict: true),
            'host_is_central' => in_array($host, array_map(strtolower(...), $central), strict: true),
            'recovery_enabled' => config()->boolean('security.forwarded_host_recovery'),
        ])->withHeaders([
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    /**
     * Mirrors ForwardedHostTenancyGuard::forwardedHost() so the probe reports the
     * value the guard would actually act on, not a second interpretation of the
     * same header.
     */
    private function normalize(?string $raw): ?string
    {
        if (! is_string($raw) || $raw === '' || strlen($raw) > self::MAX_ECHO_LENGTH) {
            return null;
        }

        $host = strtolower((string) preg_replace('/:\d+$/', '', trim(explode(',', $raw, 2)[0])));

        return $host === '' ? null : $host;
    }
}
