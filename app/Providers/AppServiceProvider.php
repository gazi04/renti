<?php

declare(strict_types=1);

namespace App\Providers;

use App\Listeners\LogImpersonationStart;
use App\Models\Plan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use STS\FilamentImpersonate\Events\EnterImpersonation;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->composeMarketingPricing();
        $this->registerPanelAssets();
    }

    /**
     * Register the panels' pre-paint theme bootstrap.
     *
     * Filament emits its own copy of this as a raw inline <script>, which the
     * CSP refuses (no 'unsafe-inline' in script-src, and Filament ships no nonce
     * support). Registering it here publishes it to public/js/app/ via
     * `php artisan filament:assets`, so it loads from 'self' like every other
     * panel asset.
     *
     * loadedOnRequest() keeps it out of @filamentScripts, which renders in the
     * body — too late to beat first paint. The panel providers emit it
     * themselves at PanelsRenderHook::HEAD_END instead.
     */
    private function registerPanelAssets(): void
    {
        FilamentAsset::register([
            Js::make('theme-bootstrap', resource_path('js/filament-theme-bootstrap.js'))
                ->loadedOnRequest(),
        ]);
    }

    /**
     * Feed live plan prices to the public marketing page.
     *
     * The pricing table used to hardcode €15/€29/€49 in the Blade markup while
     * the real prices live in the `plans` table and are editable from the admin
     * panel. An admin changing a price there would have left the landing page
     * quietly advertising the old one. A view composer keeps the route as a
     * plain Route::view (so `route:cache` still works) and runs the query only
     * when that page is actually rendered.
     */
    private function composeMarketingPricing(): void
    {
        View::composer(['marketing.home', 'marketing.pricing'], function (ViewContract $view): void {
            $view->with('plans', Plan::publiclyListed());
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        // Turn every lazy-loaded relationship into a failure in dev and CI, where
        // an N+1 is a test failure — not in production, where the same strictness
        // would turn a missed eager-load into a 500 for a customer mid-booking.
        // Same production/non-production split as prohibitDestructiveCommands above.
        Model::preventLazyLoading(! app()->isProduction());

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );

        // Queued mail (booking notifications, subscription reminders) never
        // sends faster than this, regardless of how many mailables a single
        // event queues at once — avoids tripping the SMTP provider's
        // per-second cap (see ThrottlesMailQueue).
        RateLimiter::for('mail', fn () => Limit::perSecond(1));

        // The booking confirmation and review pages return another party's
        // booking on a correct guess of an unguessable reference / signature.
        // Guessing is not the realistic threat (the reference space is 62^6),
        // but these are the only public GETs where a guess pays out, so the
        // guessing has to cost something. Generous on purpose: a customer
        // refreshing their confirmation page must never see a 429.
        RateLimiter::for('booking-links', fn (Request $request) => Limit::perMinute(30)->by((string) $request->ip()));

        // CSP violation reports: an unauthenticated POST whose only job is to
        // write log lines, so it needs a ceiling of its own.
        RateLimiter::for('csp-report', fn (Request $request) => Limit::perMinute(30)->by((string) $request->ip()));

        // The host diagnostics probe (docs/deploy-runbook.md) is an unauthenticated
        // GET that reads the domains table. It answers only booleans, so it cannot
        // enumerate tenants, but it still gets a ceiling.
        RateLimiter::for('host-diagnostics', fn (Request $request) => Limit::perMinute(10)->by((string) $request->ip()));

        // Booking-reference lookups that MISS are budgeted too, but not from here:
        // counting only failures is not something a named RateLimiter can express,
        // so ThrottleBookingReferenceMisses owns that budget. It defends the
        // confirmation page, whose references predating App\Support\BookingReference
        // carry only ~30.7 bits — not the 62^6 the 2026-09-03 review assumed.

        // Pulse dashboard access. Platform diagnostics span every tenant, so this is
        // Super-Admin-only — reusing User::isAdmin() rather than restating the
        // predicate, so "is a platform admin" has exactly one definition.
        // The dashboard is additionally pinned to the admin host via PULSE_DOMAIN;
        // see the note in config/pulse.php for why that pinning is not optional.
        Gate::define('viewPulse', fn (User $user): bool => $user->isAdmin());

        // Audit trail for admin "log in as operator" — one activity_log row per
        // start (causer = admin, subject = tenant), surfaced in the admin panel's
        // read-only Audit log resource (§15.5).
        Event::listen(EnterImpersonation::class, LogImpersonationStart::class);
    }
}
