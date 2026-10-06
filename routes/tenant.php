<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\VehicleStatus;
use App\Http\Controllers\CancelBookingController;
use App\Http\Controllers\CspReportController;
use App\Http\Controllers\DownloadAgreementController;
use App\Http\Controllers\ShowCancelBookingController;
use App\Http\Middleware\EnsureTenantIsActive;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\ThrottleBookingReferenceMisses;
use App\Models\BlockedDate;
use App\Models\Booking;
use App\Models\Vehicle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/*
|--------------------------------------------------------------------------
| Tenant Routes
|--------------------------------------------------------------------------
|
| Routes that only resolve on a tenant (operator) subdomain. The middleware
| stack resolves the tenant from the host and aborts on central domains.
|
*/

Route::middleware([
    'web',
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
    EnsureTenantIsActive::class,
    SecurityHeaders::class,
    'set-locale',
])->group(function (): void {
    // ── Public booking site ──────────────────────────────────────────────
    Route::livewire('/', 'pages::public.home')->name('public.home');

    Route::livewire('/vehicles', 'pages::public.vehicle-listing')
        ->name('public.vehicles');

    Route::livewire('/vehicles/{vehicle}', 'pages::public.vehicle-show')
        ->whereNumber('vehicle')
        ->name('public.vehicle');

    Route::livewire('/vehicles/{vehicle}/book', 'pages::public.vehicle-booking')
        ->whereNumber('vehicle')
        ->name('public.vehicle.book');

    // The only public GETs that hand back another party's booking on a correct
    // guess (vehicle, dates, total, and whether an email is on file), so both
    // carry a throttle. 30/min per IP is generous on purpose: a
    // customer refreshing their confirmation page must never see a 429.
    //
    // The reference space is NOT what the 2026-09-03 review believed. It claimed
    // 62^6, but every reference issued before App\Support\BookingReference came
    // from Str::upper(Str::random(6)) — a 62-symbol draw folded onto 36
    // non-uniformly, worth ~30.7 bits (~1.7e9), a 33x overstatement. Those
    // references are already in customers' inboxes and cannot be reissued, so
    // ThrottleBookingReferenceMisses budgets failed lookups (10/hour per IP) on
    // top of the request throttle. New references are 40 bits.
    Route::livewire('/booking/{booking:reference}/confirmation', 'pages::public.booking-confirmation')
        ->name('public.booking.confirmation')
        ->middleware(['throttle:booking-links', ThrottleBookingReferenceMisses::class])
        // Binding failure is the miss, and SubstituteBindings resolves bindings
        // BEFORE the middleware above runs (the tenancy provider's priority
        // reordering puts it there), so the middleware cannot see one. This hook
        // can: SubstituteBindings hands a failed binding straight to it.
        ->missing(function (Request $request): void {
            ThrottleBookingReferenceMisses::recordMiss($request);

            abort(404);
        });

    // Signed cancellation link, valid until the end of the booking's pickup day
    // (Booking::pickupDeadline(); deep-audit finding 07) — no auth required. Honoured while the booking is Pending or
    // Confirmed (Booking::isSelfCancellable()); Active/Completed/Cancelled show
    // a "not cancellable" page instead. GET renders a confirm page (no mutation,
    // safe for email link-prescanners); POST performs the actual cancellation.
    // Both are protected by the same signature — Laravel's hasValidSignature()
    // hashes the URL string only, not the HTTP verb, so the exact GET URL
    // doubles as a valid signed POST target with no re-signing.
    Route::get('/booking/{booking}/cancel', ShowCancelBookingController::class)
        ->name('public.booking.cancel')
        ->middleware('signed');

    Route::post('/booking/{booking}/cancel', CancelBookingController::class)
        ->name('public.booking.cancel.confirm')
        ->middleware('signed');

    // Signed ~30-day review-submission link — tokenless, no account required.
    Route::livewire('/booking/{booking}/review', 'pages::public.booking-review')
        ->name('public.booking.review')
        ->middleware(['signed', 'throttle:booking-links']);

    // Signed 7-day rental-agreement download link — no auth required.
    Route::get('/booking/{booking:reference}/agreement', DownloadAgreementController::class)
        ->name('agreement.download')
        ->middleware('signed');

    // CSP violation sink. Only the tenant group and the operator panel apply
    // SecurityHeaders, so a tenant host is the only place a CSP — and therefore a
    // report — is ever emitted; there is deliberately no central twin. A second
    // POST /csp-report without a domain constraint would in any case not coexist
    // with this one: RouteCollection keys on method + domain + URI, so the later
    // registration silently replaces the earlier.
    //
    // CSRF-exempt (bootstrap/app.php) — the browser's reporting engine sends no
    // session and no token.
    Route::post('/csp-report', CspReportController::class)
        ->name('public.csp-report')
        ->middleware('throttle:csp-report');

    // Session locale toggle — POST, redirect back.
    Route::post('/language', function (Request $request): RedirectResponse {
        $locale = $request->input('locale');
        if (in_array($locale, ['sq', 'en'], strict: true)) {
            session(['locale' => $locale]);
        }

        return back();
    })->name('public.language');

    // Operator panel language toggle — persists to users.locale so the choice
    // survives sessions/devices and drives the reminder-email locale.
    Route::get('/panel-language/{locale}', function (string $locale): RedirectResponse {
        if (in_array($locale, ['sq', 'en'], strict: true)) {
            auth()->user()->forceFill(['locale' => $locale])->save();
        }

        return back();
    })->middleware('auth')->name('operator.locale');

    // Availability JSON endpoint — feeds Flatpickr disabled ranges.
    // Route-model-bound and tenant-scoped (global scope); no cross-tenant leakage.
    // Dates are serialised date-only (Y-m-d) on purpose: a UTC datetime ("...Z")
    // makes Flatpickr parse it as UTC then re-anchor to the viewer's local day,
    // shifting the whole disabled range a day for visitors west of UTC. A bare
    // Y-m-d is parsed in local time, so the calendar is correct in every timezone.
    Route::get('/vehicles/{vehicle}/availability', function (Vehicle $vehicle) {
        abort_unless($vehicle->is_public && $vehicle->status === VehicleStatus::Available, 404);

        $toRange = fn (Booking|BlockedDate $row): array => [
            'start_date' => $row->start_date->toDateString(),
            'end_date' => $row->end_date->toDateString(),
        ];

        return response()->json([
            'unavailable' => $vehicle->bookings()
                ->whereIn('status', BookingStatus::blocking())
                ->get(['start_date', 'end_date'])
                ->map($toRange)
                ->values(),
            'blocked' => $vehicle->blockedDates()
                ->get(['start_date', 'end_date'])
                ->map($toRange)
                ->values(),
        ]);
    })->name('vehicle.availability');
});
