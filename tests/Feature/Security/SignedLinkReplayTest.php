<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Events\BookingCancelled;
use App\Models\Booking;
use App\Models\Contract;
use App\Models\Review;
use App\Models\Tenant;
use App\Models\Vehicle;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

afterEach(fn () => tenancy()->end());

/*
|--------------------------------------------------------------------------
| Signed-link replay
|--------------------------------------------------------------------------
|
| Cancel, agreement and review links are emailed to customers with no account
| behind them, and they do not expire on use. A link forwarded into a support
| ticket, or sitting in a mail archive, stays live for its whole window — cancel
| until the pickup day ends, agreement 7 days, review 30 days.
|
| The 2026-09-03 review accepted that deliberately, and the reasoning holds:
| shortening the window breaks the long-lead-time booking that is exactly when a
| customer most needs to cancel, and a one-time token breaks a legitimate
| re-visit. The link has to outlive the gap between booking and travel.
|
| What the review did NOT establish is that the trade-off is as narrow as it
| claimed. It is bounded today only because three unrelated implementations each
| happen to re-check state: a conditional UPDATE in BookingService::cancel(), a
| unique index plus two in-app guards on reviews, and a status check in
| DownloadAgreementController. Any one could regress silently and nothing would
| notice. These tests are what notice.
|
*/

function signedTenantUrl(string $subdomain, string $route, mixed $expires, array $parameters): string
{
    // Signature must be computed against the tenant subdomain so the host matches
    // the request that will carry it.
    URL::forceRootUrl(tenant_url($subdomain));

    $url = URL::temporarySignedRoute($route, $expires, $parameters);

    URL::forceRootUrl(null);

    return $url;
}

it('makes a replayed cancel link inert once the booking is cancelled', function () {
    $tenant = Tenant::factory()->withDomain('replaycancel')->create();
    $vehicle = publicVehicleFor($tenant, 'Replay Roadster');

    tenancy()->initialize($tenant);
    $booking = Booking::factory()->forVehicle($vehicle)->create();
    tenancy()->end();

    Event::fake([BookingCancelled::class]);

    $url = signedTenantUrl('replaycancel', 'public.booking.cancel', now()->addDay(), ['booking' => $booking->id]);

    test()->post($url)->assertOk();

    // Replayed from a forwarded mail three more times. BookingService::cancel()
    // is a conditional UPDATE guarded on `status != cancelled`, so the replays
    // change nothing and — the part that matters — fire no second event, which
    // would otherwise re-notify the operator and the customer on every replay.
    test()->post($url)->assertOk();
    test()->post($url)->assertOk();
    test()->get($url)->assertOk();

    expect($booking->fresh()->status)->toBe(BookingStatus::Cancelled);
    Event::assertDispatchedTimes(BookingCancelled::class, 1);
})->group('security');

it('honours the cancel link only until the booking pickup day ends', function () {
    $tenant = Tenant::factory()->withDomain('cancelwindow')->create();
    $vehicle = publicVehicleFor($tenant, 'Window Roadster');

    tenancy()->initialize($tenant);
    $booking = Booking::factory()->forVehicle($vehicle)->create([
        'start_date' => now()->addDays(30),
        'end_date' => now()->addDays(33),
    ]);
    tenancy()->end();

    // BookingReceivedMail signs the cancel link with Booking::pickupDeadline()
    // (end of the pickup day) as the expiry — not a fixed duration. Every
    // existing cancel test hard-codes addDay()/subSecond(), so the actual
    // production window was never exercised.
    $url = signedTenantUrl('cancelwindow', 'public.booking.cancel', $booking->pickupDeadline(), ['booking' => $booking->id]);

    test()->get($url)->assertOk();

    // A month later the customer is mid-rental; the emailed link is dead.
    test()->travelTo(now()->addDays(31));

    test()->get($url)->assertNotFound();
    test()->post($url)->assertNotFound();

    expect($booking->fresh()->status)->toBe(BookingStatus::Pending);
})->group('security');

it('creates no second review when a review link is replayed', function () {
    $tenant = Tenant::factory()->withDomain('replayreview')->create();

    tenancy()->initialize($tenant);
    $vehicle = Vehicle::factory()->create();
    $booking = Booking::factory()->forVehicle($vehicle)->completed()->create([
        'customer_name' => 'Arta Krasniqi',
        'customer_email' => 'arta@example.com',
    ]);
    tenancy()->end();

    $url = signedTenantUrl('replayreview', 'public.booking.review', now()->addDays(30), ['booking' => $booking->id]);

    test()->get($url)->assertOk();

    tenancy()->initialize($tenant);

    Livewire::test('pages::public.booking-review', ['booking' => $booking])
        ->set('rating', 5)
        ->set('comment', 'First and only review.')
        ->call('submit')
        ->assertSet('submitted', true);

    // The link is still perfectly valid for another 30 days. Anyone holding it —
    // a forwarded mail, a support ticket — must not be able to file a second
    // review under this customer's name, nor overwrite the first.
    Livewire::test('pages::public.booking-review', ['booking' => $booking->fresh()])
        ->assertSet('alreadyReviewed', true)
        ->set('rating', 1)
        ->set('comment', 'Forged replacement.')
        ->call('submit');

    $reviews = Review::query()->where('booking_id', $booking->id)->get();

    expect($reviews)->toHaveCount(1)
        ->and($reviews->first()->rating)->toBe(5)
        ->and($reviews->first()->comment)->toBe('First and only review.');
})->group('security');

it('refuses an agreement link after its window and does not regenerate the pdf inside it', function () {
    Storage::fake();

    $tenant = Tenant::factory()->withDomain('replayagreement')->create();

    tenancy()->initialize($tenant);
    $vehicle = Vehicle::factory()->create(['daily_rate' => 50]);
    $booking = Booking::factory()->forVehicle($vehicle)->confirmed()->create();
    tenancy()->end();

    $url = signedTenantUrl('replayagreement', 'agreement.download', now()->addDays(7), ['booking' => $booking->reference]);

    test()->get($url)->assertOk();

    tenancy()->initialize($tenant);
    $contract = Contract::query()->where('booking_id', $booking->id)->firstOrFail();
    $firstGeneratedAt = $contract->created_at;
    tenancy()->end();

    test()->travelTo(now()->addMinutes(5));

    // Replay inside the window is allowed — the customer re-opening their own
    // agreement is the feature. But it must serve the stored PDF rather than
    // re-rendering: an unauthenticated GET that runs DomPDF on every hit is a
    // free CPU amplifier for whoever holds the link.
    test()->get($url)->assertOk();

    tenancy()->initialize($tenant);
    expect(Contract::query()->where('booking_id', $booking->id)->count())->toBe(1)
        ->and(Contract::query()->where('booking_id', $booking->id)->value('created_at')->timestamp)
        ->toBe($firstGeneratedAt->timestamp);
    tenancy()->end();

    // Past the window the link is dead, whoever is holding it.
    test()->travelTo(now()->addDays(8));

    test()->get($url)->assertNotFound();
})->group('security');
