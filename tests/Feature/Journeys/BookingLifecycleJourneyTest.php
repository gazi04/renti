<?php

use App\Enums\BookingStatus;
use App\Filament\Operator\Resources\Bookings\Pages\ListBookings;
use App\Mail\BookingConfirmedMail;
use App\Models\Booking;
use App\Models\Contract;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

afterEach(fn () => tenancy()->end());

/*
 * Journey: a booking made by a customer on the storefront travels into the
 * operator's inbox, is confirmed there, and the consequences land back on the
 * storefront.
 *
 * PublicBookingTest proves the wizard writes a row; BookingManagementTest proves
 * the panel can confirm a row it created with a factory. Nothing joins the two,
 * so nothing proves the row the wizard writes is the row the panel can act on.
 */

it('carries a storefront booking into the operator inbox and back out as a confirmation', function () {
    Mail::fake();

    [$tenant, $operator] = journeyTenant('journeybook');
    tenancy()->initialize($tenant);
    // The confirm listener generates a real rental-agreement PDF, so the tenant
    // 'local' disk has to be faked or this test writes into storage/tenant{id}/.
    fakeTenantDisks();

    $vehicle = Vehicle::factory()->create(['name' => 'Inbox Golf', 'daily_rate' => 50, 'is_public' => true]);

    // Keyed per vehicle+IP with a 1h decay and not reset by RefreshDatabase, so a
    // sibling test hitting the same vehicle id could otherwise bleed into this one.
    RateLimiter::clear('booking-submit:'.$vehicle->id.':127.0.0.1');

    // ── Customer: three-step wizard ──────────────────────────────────────────
    // Livewire, not HTTP: the wizard is stateful across three steps, and a GET can
    // only ever render the first one.
    Livewire::test('pages::public.vehicle-booking', ['vehicle' => $vehicle])
        ->set('startDate', '2030-06-01')
        ->set('endDate', '2030-06-04')
        ->call('nextStep')
        ->set('customerName', 'Journey Customer')
        ->set('customerPhone', '+38344123456')
        ->set('customerEmail', 'journey@example.com')
        ->call('nextStep')
        ->set('termsAccepted', true)
        ->call('submit')
        ->assertRedirect();

    $booking = Booking::firstWhere('customer_email', 'journey@example.com');

    expect($booking)->not->toBeNull()
        ->and($booking->status)->toBe(BookingStatus::Pending);

    // ── Customer: the confirmation page the wizard redirected to ─────────────
    // HTTP: assertRedirect() above only proves a URL was emitted. This proves it
    // resolves — {booking:reference} binding, on the tenant subdomain.
    actAsVisitor();

    $this->get(tenant_url('journeybook', "/booking/{$booking->reference}/confirmation"))
        ->assertOk()
        ->assertSee($booking->reference);

    // ── Operator: the booking is waiting in the panel ────────────────────────
    // HTTP, so the panel route, tenancy resolution and auth gate are all exercised.
    actAsOperator($tenant, $operator);

    $this->get(tenant_url('journeybook', '/dashboard/bookings'))
        ->assertOk()
        ->assertSee($booking->reference);

    // ── Operator: confirm ────────────────────────────────────────────────────
    // Livewire: table actions are not addressable over HTTP.
    Livewire::test(ListBookings::class)
        ->callTableAction('confirm', $booking)
        ->assertHasNoTableActionErrors();

    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);

    // ── The fan-out the confirmation triggers ────────────────────────────────
    Mail::assertQueued(BookingConfirmedMail::class, fn (BookingConfirmedMail $mail): bool => $mail->hasTo('journey@example.com'));

    // The operator's bell notification came from the *first* step's BookingCreated
    // event, two boundaries ago — this asserts the whole chain stayed wired.
    expect(User::find($operator->id)->notifications()->count())->toBeGreaterThan(0);

    // The agreement PDF the confirm listener generated.
    expect(Contract::query()->where('booking_id', $booking->id)->exists())->toBeTrue();

    // ── Customer: the storefront now reflects the operator-side state ────────
    actAsVisitor();

    $this->get(tenant_url('journeybook', "/vehicles/{$vehicle->id}/availability"))
        ->assertOk()
        ->assertJsonPath('unavailable.0.start_date', '2030-06-01')
        ->assertJsonPath('unavailable.0.end_date', '2030-06-04');
});
