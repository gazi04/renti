<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | CSP violation reporting
    |--------------------------------------------------------------------------
    |
    | The Content-Security-Policy in App\Http\Middleware\SecurityHeaders is
    | load-bearing for tenant isolation, not defence in depth: the session cookie
    | is scoped to the parent domain, so script execution on any operator's
    | storefront would be an admin-takeover path. A regression in `script-src`
    | (a new CDN, an analytics snippet, a stray 'unsafe-inline') therefore has to
    | be visible.
    |
    | `report_uri` is the endpoint browsers POST violations to. Leave it as the
    | app's own route to have violations logged at `warning` (and picked up by
    | Sentry wherever a DSN is configured), or point it at an external collector
    | — Sentry's own security endpoint, say — with no code change. Set it to null
    | to send no reporting directive at all.
    |
    */

    'csp_report_uri' => env('CSP_REPORT_URI', '/csp-report'),

    /*
    |--------------------------------------------------------------------------
    | Forwarded-host recovery
    |--------------------------------------------------------------------------
    |
    | Tenants are resolved from $request->getHost() by InitializeTenancyByDomain,
    | and the tenant route group carries no Route::domain() constraint. Laravel
    | Cloud publishes no documentation of whether its load balancer preserves the
    | original Host header. If it rewrites Host to its own hostname and passes the
    | real one only in X-Forwarded-Host, nothing resolves and EVERY tenant
    | subdomain 404s — a total outage whose 404 is indistinguishable from an
    | ordinary unknown-subdomain 404.
    |
    | This flag does not trust X-Forwarded-Host; the header stays out of
    | trustProxies() in bootstrap/app.php permanently. It *validates* it: the
    | forwarded host is honoured only when it exactly matches a row in the
    | `domains` table AND the real Host resolves to nothing at all — not a central
    | domain, not a tenant domain. An attacker who can reach the origin directly
    | and set X-Forwarded-Host can equally set Host to the same value for the same
    | result, so this grants no capability a direct-to-origin request did not
    | already have. What it buys is that a load balancer which rewrites Host stops
    | being an outage that needs a code deploy to fix.
    |
    | Leave it false.
    | shows the load balancer rewriting Host, and turn it back off once the load
    | balancer is fixed.
    |
    */

    'forwarded_host_recovery' => env('FORWARDED_HOST_RECOVERY', false),

    /*
    |--------------------------------------------------------------------------
    | Host diagnostics probe
    |--------------------------------------------------------------------------
    |
    | Exposes GET /_diagnostics/host, which reports what the app actually sees for
    | Host, X-Forwarded-Host, scheme and port. It exists for the first-deploy, which cannot be run any other way: an Artisan
    | command has no request, so it has no Host header to report on.
    |
    | The two "does this resolve to a tenant" answers are booleans, never a tenant
    | id, name or subdomain, so the endpoint cannot be used to enumerate which
    | subdomains exist. It is throttled regardless. Turn it on for the probe, then
    | turn it back off.
    |
    */

    'host_diagnostics_enabled' => env('HOST_DIAGNOSTICS_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | HTTP Strict Transport Security
    |--------------------------------------------------------------------------
    |
    | Emitted by App\Http\Middleware\StrictTransportSecurity on every HTTPS
    | response. It tells the browser to refuse plaintext HTTP for this origin,
    | which is what removes the SSL-stripping window around the parent-domain
    | session cookie (SESSION_DOMAIN) and the remember-me cookie that inherits
    | its settings.
    |
    | `max_age` starts deliberately low. HSTS is cached by the browser for the
    | full duration and cannot be withdrawn early -- a wrong `include_subdomains`
    | makes every plain-HTTP subdomain unreachable for that long, for every
    | visitor who already saw the header. Verify in production first, then raise
    | this to 31536000 (one year), which is the value the header is worth having.
    | Set it to 0 to stop sending the header at all.
    |
    | `include_subdomains` defaults ON precisely because the session cookie is
    | parent-scoped: without it, a subdomain reachable over plain HTTP can set or
    | overwrite a cookie that the parent domain will then honour, which is a
    | session-fixation path that HTTPS on the parent alone does not close.
    |
    | `preload` stays OFF. Setting it is a request to be hardcoded into browser
    | binaries, and removal from that list takes months to reach users. Turn it
    | on only when every subdomain, forever, is HTTPS-only.
    |
    */

    'hsts_max_age' => env('HSTS_MAX_AGE', 86400),

    'hsts_include_subdomains' => env('HSTS_INCLUDE_SUBDOMAINS', true),

    'hsts_preload' => env('HSTS_PRELOAD', false),

];
