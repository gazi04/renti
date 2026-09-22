<?php

use App\Enums\BookingStatus;
use App\Enums\VehicleStatus;
use App\Filament\Operator\Resources\Bookings\Pages\ListBookings;
use App\Models\Booking;
use App\Models\Vehicle;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

afterEach(fn () => tenancy()->end());

/*
 * Journey: one customer's booking closes a window for the next customer, and an
 * operator rejection reopens it.
 *
 * AvailabilityServiceTest proves the conflict rule in isolation and
 * PublicBookingTest proves the date filter excludes a conflicting booking — but
 * against a booking a factory inserted. This walks the real sequence: a booking
 * made through the wizard, observed through the public filter and the public
 * availability endpoint, then undone through a panel action.
 */

it('closes a booked window to the next customer, then reopens it when the operator rejects', function () {
    Mail::fake();

    [$tenant, $operator] = journeyTenant('journeywindow');
    tenancy()->initialize($tenant);
    fakeTenantDisks();

    $booked = Vehicle::factory()->create(['name' => 'Contested Golf', 'daily_rate' => 50, 'is_public' => true, 'status' => VehicleStatus::Available]);
    $spare = Vehicle::factory()->create(['name' => 'Free Passat', 'daily_rate' => 50, 'is_public' => true, 'status' => VehicleStatus::Available]);

    foreach ([$booked, $spare] as $vehicle) {
        RateLimiter::clear('booking-submit:'.$vehicle->id.':127.0.0.1');
    }

    // ── Customer A books ─────────────────────────────────────────────────────
    Livewire::test('pages::public.vehicle-booking', ['vehicle' => $booked])
        ->set('startDate', '2030-06-01')
        ->set('endDate', '2030-06-04')
        ->call('nextStep')
        ->set('customerName', 'Customer A')
        ->set('customerPhone', '+38344111111')
        ->set('customerEmail', 'a@example.com')
        ->call('nextStep')
        ->set('termsAccepted', true)
        ->call('submit')
        ->assertRedirect();

    $bookingA = Booking::firstWhere('customer_email', 'a@example.com');

    // ── The window is visibly closed on the storefront ───────────────────────
    // HTTP with the real query string, so the listing's date filter is exercised
    // end to end rather than by calling the scope directly.
    actAsVisitor();

    $this->get(tenant_url('journeywindow', '/vehicles?start_date=2030-06-02&end_date=2030-06-03'))
        ->assertOk()
        ->assertSee('Free Passat')
        ->assertDontSee('Contested Golf');

    $this->get(tenant_url('journeywindow', "/vehicles/{$booked->id}/availability"))
        ->assertOk()
        ->assertJsonPath('unavailable.0.start_date', '2030-06-01');

    // ── Customer B is turned away from the same window ───────────────────────
    // Livewire, for the wizard's stateful steps. Pins that a merely *pending*
    // booking is already blocking — no operator action required.
    tenancy()->initialize($tenant);

    Livewire::test('pages::public.vehicle-booking', ['vehicle' => $booked])
        ->set('startDate', '2030-06-01')
        ->set('endDate', '2030-06-04')
        ->call('nextStep')
        ->set('customerName', 'Customer B')
        ->set('customerPhone', '+38344222222')
        ->set('customerEmail', 'b@example.com')
        ->call('nextStep')
        ->set('termsAccepted', true)
        ->call('submit')
        ->assertSet('slotTaken', true);

    expect(Booking::count())->toBe(1)
        ->and($bookingA->fresh()->status)->toBe(BookingStatus::Pending);

    // ── Operator rejects, and the window reopens ─────────────────────────────
    actAsOperator($tenant, $operator);

    Livewire::test(ListBookings::class)
        ->callTableAction('reject', $bookingA, ['reason' => 'Vehicle recalled'])
        ->assertHasNoTableActionErrors();

    expect($bookingA->fresh()->status)->toBe(BookingStatus::Cancelled);

    actAsVisitor();

    $this->get(tenant_url('journeywindow', '/vehicles?start_date=2030-06-02&end_date=2030-06-03'))
        ->assertOk()
        ->assertSee('Contested Golf')
        ->assertSee('Free Passat');
});
