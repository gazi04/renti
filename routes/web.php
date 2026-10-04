<?php

use App\Enums\MarketingPage;
use App\Http\Controllers\HostDiagnosticsController;
use App\Http\Controllers\ResendWebhookController;
use App\Http\Controllers\RobotsController;
use App\Http\Controllers\SitemapController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Central (marketing) host only. Pinned so these don't collide with the operator
// Filament panel, which serves "/dashboard" on every tenant subdomain (no fixed domain).
// The public booking site owns "/" on tenant subdomains (routes/tenant.php).
Route::domain(config('tenancy.central_domain'))->middleware('set-locale')->group(function (): void {
    // The marketing pages live under a language prefix (/sq, /en); "/" only picks one.
    // 302, not 301: the target follows the visitor's session language. The query
    // string is kept for Fortify's "/?verified=1" fallback redirect.
    Route::get('/', function (Request $request): RedirectResponse {
        $query = $request->getQueryString();

        return redirect()->to(
            MarketingPage::Home->url(MarketingPage::normalizeLocale(session('locale')))
            .($query !== null ? '?'.$query : ''),
        );
    })->name('home');

    // Operator self-registration: creates a pending tenant awaiting admin approval.
    Route::livewire('signup', 'pages::auth.operator-register')->name('operator.register');
});

// The marketing pages, one route per page per locale with a translated slug
// (/sq/cmimet, /en/pricing). The language comes from the URL, not the session —
// see SetMarketingLocale — so this group deliberately does not carry set-locale.
// Registered from a static enum, so route:cache freezes nothing conditional.
Route::domain(config()->string('tenancy.central_domain'))->group(function (): void {
    foreach (MarketingPage::cases() as $page) {
        foreach (MarketingPage::LOCALES as $locale) {
            Route::view($page->path($locale), $page->view(), ['page' => $page->value])
                ->middleware("marketing-locale:{$locale}")
                ->name($page->routeName($locale));
        }
    }

    Route::get('sitemap.xml', SitemapController::class)->name('marketing.sitemap');
});

// Domain-less: every host answers robots.txt, only the central one adds a Sitemap line.
Route::get('robots.txt', RobotsController::class)->name('robots');

// Resend delivery webhook (email log status updates). Central host, no tenant
// middleware; unauthenticated but Svix-signature-verified in the controller,
// and CSRF-exempt (see bootstrap/app.php).
Route::domain(config('tenancy.central_domain'))
    ->post('webhooks/resend', ResendWebhookController::class)
    ->name('webhooks.resend');

// Deploy-time host probe. DELIBERATELY carries no Route::domain() constraint: it
// has to answer while tenant resolution is broken, and a load balancer that has
// rewritten Host is by definition not sending the central domain — pinning it
// would 404 in exactly the case it exists to diagnose. Domain-less is also what
// lets the runbook's "hit a tenant subdomain" instruction work.
//
// Off by default and gated inside the controller (not by conditional
// registration, which route:cache would freeze). See docs/deploy-runbook.md.
Route::get('/_diagnostics/host', HostDiagnosticsController::class)
    ->name('diagnostics.host')
    ->middleware('throttle:host-diagnostics');
