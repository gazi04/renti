<?php

use App\Enums\BookingStatus;
use App\Enums\VehicleStatus;
use App\Events\BookingCancelled;
use App\Models\Booking;
use App\Models\Tenant;
use App\Models\Vehicle;
use App\Services\PricingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

afterEach(fn () => tenancy()->end());

// ── Test helpers ────────────────────────────────────────────────────────────

function publicTenant(string $subdomain): Tenant
{
    return Tenant::factory()->withDomain($subdomain)->create();
}

function publicVehicle(array $attrs = []): Vehicle
{
    return Vehicle::factory()->create(array_merge([
        'is_public' => true,
        'status' => VehicleStatus::Available,
        'daily_rate' => 50,
        'hourly_rate' => null,
        'weekly_rate' => null,
        'monthly_rate' => null,
    ], $attrs));
}

// ── Listing ──────────────────────────────────────────────────────────────────

it('shows public vehicles on the listing and hides private ones', function () {
    $tenant = publicTenant('ardi');
    tenancy()->initialize($tenant);

    $visible = publicVehicle(['name' => 'Toyota Corolla']);
    Vehicle::factory()->private()->create(['name' => 'Hidden Car']);
    // Listed since the stock alert (#3) — its page is where you ask to hear when
    // it comes back, so removing it would leave nothing to click. It carries an
    // "unavailable" badge instead of a booking CTA.
    Vehicle::factory()->underMaintenance()->create(['name' => 'Broken Car']);

    tenancy()->end();

    $this->get(tenant_url('ardi', '/vehicles'))
        ->assertOk()
        ->assertSee('Toyota Corolla')
        ->assertSee('Broken Car')
        ->assertSee(__('booking.vehicle_unavailable_badge'))
        // is_public stays absolute: hiding a vehicle keeps meaning hidden.
        ->assertDontSee('Hidden Car');
});

it('renders the listing for vehicles that already have photos', function () {
    $tenant = publicTenant('ardiphotos');
    tenancy()->initialize($tenant);
    fakeTenantDisks();

    // One photo each across two vehicles is enough: the listing eager-loads
    // media for the whole page, so the hydration is two rows — and a multi-row
    // hydration is the only thing that arms preventLazyLoading() on Media.
    // Without that, nothing here exercises the tenant-aware path generator
    // against media read back from the database.
    $first = publicVehicle(['name' => 'Photographed Golf']);
    $second = publicVehicle(['name' => 'Photographed Passat']);
    attachVehiclePhotos($first, 1);
    attachVehiclePhotos($second, 1);

    tenancy()->end();

    $this->get(tenant_url('ardiphotos', '/vehicles'))
        ->assertOk()
        ->assertSee('Photographed Golf')
        ->assertSee('Photographed Passat')
        ->assertSee("/storage/tenants/{$tenant->id}/vehicle_photos/", escape: false);
});

it('does not show tenant B vehicles on tenant A subdomain', function () {
    $tenantA = publicTenant('alpha');
    $tenantB = publicTenant('beta');

    tenancy()->initialize($tenantA);
    publicVehicle(['name' => 'Alpha Car']);
    tenancy()->end();

    tenancy()->initialize($tenantB);
    publicVehicle(['name' => 'Beta Car']);
    tenancy()->end();

    $this->get(tenant_url('alpha', '/vehicles'))
        ->assertSee('Alpha Car')
        ->assertDontSee('Beta Car');
});

it('filters vehicles by category', function () {
    $tenant = publicTenant('ardi');
    tenancy()->initialize($tenant);

    publicVehicle(['name' => 'Sedan Car', 'category' => 'sedan']);
    publicVehicle(['name' => 'SUV Car', 'category' => 'suv']);

    tenancy()->end();

    $this->get(tenant_url('ardi', '/vehicles?category=sedan'))
        ->assertSee('Sedan Car')
        ->assertDontSee('SUV Car');
});

it('filters vehicles by transmission', function () {
    $tenant = publicTenant('ardi');
    tenancy()->initialize($tenant);

    publicVehicle(['name' => 'Manual Car', 'transmission' => 'manual']);
    publicVehicle(['name' => 'Auto Car', 'transmission' => 'automatic']);

    tenancy()->end();

    $this->get(tenant_url('ardi', '/vehicles?transmission=manual'))
        ->assertSee('Manual Car')
        ->assertDontSee('Auto Car');
});

it('filters vehicles by max price', function () {
    $tenant = publicTenant('ardi');
    tenancy()->initialize($tenant);

    publicVehicle(['name' => 'Cheap Car', 'daily_rate' => 30]);
    publicVehicle(['name' => 'Expensive Car', 'daily_rate' => 200]);

    tenancy()->end();

    $this->get(tenant_url('ardi', '/vehicles?maxPrice=50'))
        ->assertSee('Cheap Car')
        ->assertDontSee('Expensive Car');
});

it('filters vehicles by fuel type', function () {
    $tenant = publicTenant('ardi');
    tenancy()->initialize($tenant);

    publicVehicle(['name' => 'Electric Car', 'fuel_type' => 'electric']);
    publicVehicle(['name' => 'Petrol Car', 'fuel_type' => 'petrol']);

    tenancy()->end();

    $this->get(tenant_url('ardi', '/vehicles?fuelType=electric'))
        ->assertSee('Electric Car')
        ->assertDontSee('Petrol Car');
});

it('filters vehicles by minimum seats', function () {
    $tenant = publicTenant('ardi');
    tenancy()->initialize($tenant);

    publicVehicle(['name' => 'Small Car', 'seats' => 2]);
    publicVehicle(['name' => 'Big Van', 'seats' => 7]);

    tenancy()->end();

    $this->get(tenant_url('ardi', '/vehicles?seats=5'))
        ->assertSee('Big Van')
        ->assertDontSee('Small Car');
});

it('filters vehicles by year', function () {
    $tenant = publicTenant('ardi');
    tenancy()->initialize($tenant);

    publicVehicle(['name' => 'Old Car', 'year' => 2016]);
    publicVehicle(['name' => 'New Car', 'year' => 2024]);

    tenancy()->end();

    $this->get(tenant_url('ardi', '/vehicles?year=2024'))
        ->assertSee('New Car')
        ->assertDontSee('Old Car');
});

it('searches vehicles by name, case-insensitively', function () {
    $tenant = publicTenant('ardi');
    tenancy()->initialize($tenant);

    publicVehicle(['name' => 'Toyota Corolla']);
    publicVehicle(['name' => 'BMW 320i']);

    tenancy()->end();

    $this->get(tenant_url('ardi', '/vehicles?search=corolla'))
        ->assertSee('Toyota Corolla')
        ->assertDontSee('BMW 320i');
});

it('sorts vehicles by price ascending and descending', function () {
    $tenant = publicTenant('ardi');
    tenancy()->initialize($tenant);

    publicVehicle(['name' => 'Cheap Car', 'daily_rate' => 30]);
    publicVehicle(['name' => 'Expensive Car', 'daily_rate' => 200]);

    tenancy()->end();

    $asc = $this->get(tenant_url('ardi', '/vehicles?sort=price_asc'))->getContent();
    expect(strpos($asc, 'Cheap Car'))->toBeLessThan(strpos($asc, 'Expensive Car'));

    $desc = $this->get(tenant_url('ardi', '/vehicles?sort=price_desc'))->getContent();
    expect(strpos($desc, 'Expensive Car'))->toBeLessThan(strpos($desc, 'Cheap Car'));
});

it('excludes vehicles with a conflicting booking from the availability date filter', function () {
    $tenant = publicTenant('ardi');
    tenancy()->initialize($tenant);

    $booked = publicVehicle(['name' => 'Booked Car']);
    Booking::factory()->forVehicle($booked)->confirmed()->create([
        'start_date' => '2030-06-01',
        'end_date' => '2030-06-05',
    ]);

    publicVehicle(['name' => 'Free Car']);

    tenancy()->end();

    $this->get(tenant_url('ardi', '/vehicles?start_date=2030-06-02&end_date=2030-06-04'))
        ->assertSee('Free Car')
        ->assertDontSee('Booked Car');
});

it('ignores a malformed date-range filter instead of erroring', function () {
    $tenant = publicTenant('ardi');
    tenancy()->initialize($tenant);

    publicVehicle(['name' => 'Any Car']);

    tenancy()->end();

    $this->get(tenant_url('ardi', '/vehicles?start_date=not-a-date&end_date=also-not-a-date'))
        ->assertOk()
        ->assertSee('Any Car');
});

// ── Availability endpoint ────────────────────────────────────────────────────

it('returns blocking bookings and blocked dates for a vehicle', function () {
    $tenant = publicTenant('ardi');
    tenancy()->initialize($tenant);

    $vehicle = publicVehicle();

    Booking::factory()->forVehicle($vehicle)->confirmed()->create([
        'start_date' => '2030-06-01',
        'end_date' => '2030-06-05',
    ]);

    tenancy()->end();

    $this->get(tenant_url('ardi', "/vehicles/{$vehicle->id}/availability"))
        ->assertOk()
        ->assertJsonCount(1, 'unavailable')
        ->assertJsonCount(0, 'blocked');
});

it('serialises availability dates as date-only Y-m-d, not UTC datetimes', function () {
    // A "...T00:00:00Z" payload makes Flatpickr re-anchor the disabled range to the
    // viewer's local day, shifting the calendar a day for visitors west of UTC.
    // Bare Y-m-d is parsed in local time and stays correct in every timezone.
    $tenant = publicTenant('ardi');
    tenancy()->initialize($tenant);

    $vehicle = publicVehicle();

    Booking::factory()->forVehicle($vehicle)->confirmed()->create([
        'start_date' => '2030-06-01',
        'end_date' => '2030-06-05',
    ]);

    tenancy()->end();

    $response = $this->get(tenant_url('ardi', "/vehicles/{$vehicle->id}/availability"))
        ->assertOk();

    $range = $response->json('unavailable.0');

    expect($range['start_date'])->toBe('2030-06-01')
        ->and($range['end_date'])->toBe('2030-06-05')
        ->and($range['start_date'])->toMatch('/^\d{4}-\d{2}-\d{2}$/');
});

it('returns 404 for the availability endpoint on a private (unlisted) vehicle', function () {
    $tenant = publicTenant('ardi');
    tenancy()->initialize($tenant);

    $vehicle = Vehicle::factory()->private()->create();

    tenancy()->end();

    $this->get(tenant_url('ardi', "/vehicles/{$vehicle->id}/availability"))
        ->assertNotFound();
});

it('returns 404 for the availability endpoint on a vehicle under maintenance', function () {
    $tenant = publicTenant('ardi');
    tenancy()->initialize($tenant);

    $vehicle = Vehicle::factory()->underMaintenance()->create();

    tenancy()->end();

    $this->get(tenant_url('ardi', "/vehicles/{$vehicle->id}/availability"))
        ->assertNotFound();
});

it('returns 404 for availability endpoint on another tenant vehicle', function () {
    $tenantA = publicTenant('aaa');
    $tenantB = publicTenant('bbb');

    tenancy()->initialize($tenantA);
    $vehicle = publicVehicle();
    tenancy()->end();

    // Request from tenant B — vehicle is not visible (global scope filters it).
    $this->get(tenant_url('bbb', "/vehicles/{$vehicle->id}/availability"))
        ->assertNotFound();
});

// ── Booking wizard ───────────────────────────────────────────────────────────

it('creates a pending booking on submit and redirects to confirmation', function () {
    $tenant = publicTenant('ardi');
    tenancy()->initialize($tenant);
    $vehicle = publicVehicle();

    Livewire::test('pages::public.vehicle-booking', ['vehicle' => $vehicle])
        ->set('startDate', '2030-06-01')
        ->set('endDate', '2030-06-04')
        ->call('nextStep')           // advance to step 2
        ->set('customerName', 'Gazi Halili')
        ->set('customerPhone', '+38344123456')
        ->set('customerEmail', 'gazi@example.com')
        ->call('nextStep')           // advance to step 3
        ->call('submit')
        ->assertRedirect();

    expect(Booking::count())->toBe(1)
        ->and(Booking::first()->status)->toBe(BookingStatus::Pending)
        ->and(Booking::first()->customer_name)->toBe('Gazi Halili');
});

it('price preview matches PricingService::calculate', function () {
    $tenant = publicTenant('ardi');
    tenancy()->initialize($tenant);
    $vehicle = publicVehicle(['daily_rate' => 60]);

    $component = Livewire::test('pages::public.vehicle-booking', ['vehicle' => $vehicle])
        ->set('startDate', '2030-06-01')
        ->set('endDate', '2030-06-04');

    // Trigger the on handler manually.
    $component->dispatch('dates-selected', start: '2030-06-01', end: '2030-06-04');

    $expected = app(PricingService::class)->calculate(
        $vehicle,
        Carbon::parse('2030-06-01'),
        Carbon::parse('2030-06-04'),
    );

    $component->assertSet('priceBreakdown.total', $expected['total']);
});

it('prefills dates from the query string forwarded off the listing filter', function () {
    $tenant = publicTenant('ardi');
    tenancy()->initialize($tenant);
    $vehicle = publicVehicle(['daily_rate' => 60]);
    tenancy()->end();

    $expected = app(PricingService::class)->calculate(
        $vehicle,
        Carbon::parse('2030-06-01'),
        Carbon::parse('2030-06-04'),
    );

    $this->get(tenant_url('ardi', "/vehicles/{$vehicle->id}/book?start_date=2030-06-01&end_date=2030-06-04"))
        ->assertOk()
        ->assertSee(number_format($expected['total'], 2));
});

it('blocks advancing from step 1 when dates are missing', function () {
    $tenant = publicTenant('ardi');
    tenancy()->initialize($tenant);
    $vehicle = publicVehicle();

    Livewire::test('pages::public.vehicle-booking', ['vehicle' => $vehicle])
        ->call('nextStep')
        ->assertHasErrors(['startDate', 'endDate'])
        ->assertSet('step', 1);
});

it('blocks advancing from step 1 when end is before start', function () {
    $tenant = publicTenant('ardi');
    tenancy()->initialize($tenant);
    $vehicle = publicVehicle();

    Livewire::test('pages::public.vehicle-booking', ['vehicle' => $vehicle])
        ->set('startDate', '2030-06-10')
        ->set('endDate', '2030-06-05')
        ->call('nextStep')
        ->assertHasErrors(['endDate'])
        ->assertSet('step', 1);
});

// ── Booking window (deep-audit finding 02) ───────────────────────────────────

it('blocks advancing from step 1 when the start date is in the past', function () {
    $tenant = publicTenant('ardi');
    tenancy()->initialize($tenant);
    $vehicle = publicVehicle();

    Livewire::test('pages::public.vehicle-booking', ['vehicle' => $vehicle])
        ->set('startDate', today()->subDay()->toDateString())
        ->set('endDate', today()->addDays(3)->toDateString())
        ->call('nextStep')
        ->assertHasErrors(['startDate'])
        ->assertSet('step', 1);
});

it('blocks advancing from step 1 when the range is longer than the maximum', function () {
    $tenant = publicTenant('ardi');
    tenancy()->initialize($tenant);
    $vehicle = publicVehicle();
    $start = today()->addDay();

    Livewire::test('pages::public.vehicle-booking', ['vehicle' => $vehicle])
        ->set('startDate', $start->toDateString())
        ->set('endDate', $start->copy()->addDays(config('bookings.max_rental_days') + 1)->toDateString())
        ->call('nextStep')
        ->assertHasErrors(['endDate'])
        ->assertSet('step', 1);
});

it('ignores a forged dates-selected event carrying an unbookable window', function () {
    // The event comes from booking-form.js, so it is browser input: flatpickr's
    // minDate/maxDate are feedback, and this is the server saying no.
    $tenant = publicTenant('ardi');
    tenancy()->initialize($tenant);
    $vehicle = publicVehicle();

    Livewire::test('pages::public.vehicle-booking', ['vehicle' => $vehicle])
        ->dispatch('dates-selected', start: '2023-01-01', end: '2033-01-01')
        ->assertSet('startDate', '')
        ->assertSet('endDate', '')
        ->assertSet('priceBreakdown', null);
});

it('accepts a dates-selected event for a bookable window', function () {
    $tenant = publicTenant('ardi');
    tenancy()->initialize($tenant);
    $vehicle = publicVehicle();
    $start = today()->addDays(2)->toDateString();
    $end = today()->addDays(5)->toDateString();

    Livewire::test('pages::public.vehicle-booking', ['vehicle' => $vehicle])
        ->dispatch('dates-selected', start: $start, end: $end)
        ->assertSet('startDate', $start)
        ->assertSet('endDate', $end)
        ->assertSet('priceBreakdown', fn (?array $value): bool => $value !== null);
});

it('drops a past-dated prefill from the query string', function () {
    $tenant = publicTenant('ardi');
    tenancy()->initialize($tenant);
    $vehicle = publicVehicle();

    Livewire::withQueryParams(['start_date' => '2023-01-01', 'end_date' => '2023-01-05'])
        ->test('pages::public.vehicle-booking', ['vehicle' => $vehicle])
        ->assertSet('startDate', '')
        ->assertSet('endDate', '');
});

it('blocks advancing from step 2 when required details are missing', function () {
    $tenant = publicTenant('ardi');
    tenancy()->initialize($tenant);
    $vehicle = publicVehicle();

    Livewire::test('pages::public.vehicle-booking', ['vehicle' => $vehicle])
        ->set('startDate', '2030-06-01')
        ->set('endDate', '2030-06-04')
        ->set('step', 2)             // jump to step 2 directly
        ->call('nextStep')
        ->assertHasErrors(['customerName', 'customerPhone'])
        ->assertSet('step', 2);
});

it('requires a customer email to advance from step 2', function () {
    $tenant = publicTenant('ardi');
    tenancy()->initialize($tenant);
    $vehicle = publicVehicle();

    Livewire::test('pages::public.vehicle-booking', ['vehicle' => $vehicle])
        ->set('startDate', '2030-06-01')
        ->set('endDate', '2030-06-04')
        ->set('step', 2)
        ->set('customerName', 'Gazi Halili')
        ->set('customerPhone', '+38344123456')
        ->call('nextStep')                     // email still empty
        ->assertHasErrors(['customerEmail'])
        ->assertSet('step', 2)
        ->set('customerEmail', 'gazi@example.com')
        ->call('nextStep')                     // now valid
        ->assertHasNoErrors()
        ->assertSet('step', 3);
});

it('bounces to step 1 and shows slot-taken flash on double booking', function () {
    $tenant = publicTenant('ardi');
    tenancy()->initialize($tenant);
    $vehicle = publicVehicle();

    Booking::factory()->forVehicle($vehicle)->confirmed()->create([
        'start_date' => '2030-06-01',
        'end_date' => '2030-06-04',
    ]);

    Livewire::test('pages::public.vehicle-booking', ['vehicle' => $vehicle])
        ->set('startDate', '2030-06-01')
        ->set('endDate', '2030-06-04')
        ->set('customerName', 'Test Renter')
        ->set('customerPhone', '+38344000000')
        ->set('customerEmail', 'renter@example.com')
        ->set('step', 3)
        ->call('submit')
        ->assertSet('slotTaken', true)
        ->assertSet('step', 1);

    expect(Booking::count())->toBe(1); // only the pre-existing one
});

it('throttles repeated booking submissions from the same visitor', function () {
    $tenant = publicTenant('ardi');
    tenancy()->initialize($tenant);
    $vehicle = publicVehicle();
    RateLimiter::clear('booking-submit:'.$vehicle->id.':127.0.0.1');

    foreach (range(0, 4) as $i) {
        Livewire::test('pages::public.vehicle-booking', ['vehicle' => $vehicle])
            ->set('startDate', now()->addDays(10 + $i * 3)->toDateString())
            ->set('endDate', now()->addDays(12 + $i * 3)->toDateString())
            ->set('customerName', 'Test Renter')
            ->set('customerPhone', '+38344000000')
            ->set('customerEmail', "spam{$i}@example.com")
            ->set('step', 3)
            ->call('submit')
            ->assertSet('submitError', null);
    }

    Livewire::test('pages::public.vehicle-booking', ['vehicle' => $vehicle])
        ->set('startDate', now()->addDays(40)->toDateString())
        ->set('endDate', now()->addDays(42)->toDateString())
        ->set('customerName', 'Test Renter')
        ->set('customerPhone', '+38344000000')
        ->set('customerEmail', 'spam6@example.com')
        ->set('step', 3)
        ->call('submit')
        ->assertSet('submitError', __('booking.submit_throttled'));

    expect(Booking::count())->toBe(5);
});

it('does not charge the submission throttle for validation failures', function () {
    // Regression guard for the submit() reorder that closed the check-then-hit
    // race (deep-audit finding 09): hit() now runs later, right before the
    // create() attempt, so a bad submission still must not consume budget —
    // 5 invalid attempts followed by a 6th, valid one must all succeed in
    // reaching validation, and the valid one must not be throttled.
    $tenant = publicTenant('ardi');
    tenancy()->initialize($tenant);
    $vehicle = publicVehicle();
    RateLimiter::clear('booking-submit:'.$vehicle->id.':127.0.0.1');

    foreach (range(0, 4) as $i) {
        Livewire::test('pages::public.vehicle-booking', ['vehicle' => $vehicle])
            ->set('startDate', now()->addDays(10 + $i * 3)->toDateString())
            ->set('endDate', now()->addDays(12 + $i * 3)->toDateString())
            ->set('customerName', '')
            ->set('customerPhone', '+38344000000')
            ->set('customerEmail', 'invalid@example.com')
            ->set('step', 3)
            ->call('submit')
            ->assertHasErrors(['customerName' => 'required']);
    }

    Livewire::test('pages::public.vehicle-booking', ['vehicle' => $vehicle])
        ->set('startDate', now()->addDays(40)->toDateString())
        ->set('endDate', now()->addDays(42)->toDateString())
        ->set('customerName', 'Test Renter')
        ->set('customerPhone', '+38344000000')
        ->set('customerEmail', 'valid@example.com')
        ->set('step', 3)
        ->call('submit')
        ->assertSet('submitError', null);

    expect(Booking::count())->toBe(1);
});

it('throttles submissions fleet-wide once a visitor spreads attempts across many vehicles', function () {
    // Round-2 hardening: the per-vehicle cap alone bounds nothing in aggregate —
    // cycling across the fleet gets a fresh 5-attempt budget per vehicle. This
    // proves the tenant-wide + IP companion cap catches that, even though each
    // individual vehicle here is only ever booked once (nowhere near its own
    // per-vehicle limit of 5).
    $tenant = publicTenant('ardi');
    tenancy()->initialize($tenant);
    RateLimiter::clear('booking-submit-tenant:'.$tenant->id.':127.0.0.1');

    foreach (range(0, 19) as $i) {
        $vehicle = publicVehicle();

        Livewire::test('pages::public.vehicle-booking', ['vehicle' => $vehicle])
            ->set('startDate', now()->addDays(10 + $i * 3)->toDateString())
            ->set('endDate', now()->addDays(12 + $i * 3)->toDateString())
            ->set('customerName', 'Test Renter')
            ->set('customerPhone', '+38344000000')
            ->set('customerEmail', "fleet{$i}@example.com")
            ->set('step', 3)
            ->call('submit')
            ->assertSet('submitError', null);
    }

    // A 21st, still-fresh vehicle — its own per-vehicle counter is at 0, so only
    // the tenant-wide cap can be the reason this is rejected.
    $freshVehicle = publicVehicle();

    Livewire::test('pages::public.vehicle-booking', ['vehicle' => $freshVehicle])
        ->set('startDate', now()->addDays(90)->toDateString())
        ->set('endDate', now()->addDays(92)->toDateString())
        ->set('customerName', 'Test Renter')
        ->set('customerPhone', '+38344000000')
        ->set('customerEmail', 'fleet20@example.com')
        ->set('step', 3)
        ->call('submit')
        ->assertSet('submitError', __('booking.submit_throttled'));

    expect(Booking::count())->toBe(20);
});

// ── Confirmation page ────────────────────────────────────────────────────────

it('confirmation page shows reference and pending notice', function () {
    $tenant = publicTenant('ardi');
    tenancy()->initialize($tenant);
    $vehicle = publicVehicle();

    $booking = Booking::factory()->forVehicle($vehicle)->create(['reference' => 'BK-2030-TESTOK']);
    tenancy()->end();

    $this->get(tenant_url('ardi', '/booking/BK-2030-TESTOK/confirmation'))
        ->assertOk()
        ->assertSee('BK-2030-TESTOK');
});

it('confirmation page is not reachable for another tenant booking', function () {
    $tenantA = publicTenant('aaa2');
    $tenantB = publicTenant('bbb2');

    tenancy()->initialize($tenantA);
    $vehicle = publicVehicle();
    Booking::factory()->forVehicle($vehicle)->create(['reference' => 'BK-2030-CROSS1']);
    tenancy()->end();

    // Access from tenant B → global scope filters → 404.
    $this->get(tenant_url('bbb2', '/booking/BK-2030-CROSS1/confirmation'))
        ->assertNotFound();
});

// ── Cancellation ─────────────────────────────────────────────────────────────

it('GET on a valid signed cancel link shows a confirm page without cancelling', function () {
    $tenant = publicTenant('ardi');
    tenancy()->initialize($tenant);
    $vehicle = publicVehicle();
    $booking = Booking::factory()->forVehicle($vehicle)->create();
    tenancy()->end();

    // Signature must be computed for the tenant subdomain so the host matches the request.
    URL::forceRootUrl(tenant_url('ardi'));
    $url = URL::temporarySignedRoute(
        'public.booking.cancel',
        now()->addDay(),
        ['booking' => $booking->id],
    );

    $this->get($url)
        ->assertOk()
        ->assertSee($booking->reference)
        ->assertSee(__('booking.confirm_cancel_button'));

    expect($booking->fresh()->status)->toBe(BookingStatus::Pending);
});

it('POST on a valid signed cancel link cancels the booking', function () {
    $tenant = publicTenant('ardi');
    tenancy()->initialize($tenant);
    $vehicle = publicVehicle();
    $booking = Booking::factory()->forVehicle($vehicle)->create();
    tenancy()->end();

    URL::forceRootUrl(tenant_url('ardi'));
    $url = URL::temporarySignedRoute(
        'public.booking.cancel',
        now()->addDay(),
        ['booking' => $booking->id],
    );

    $this->post($url)
        ->assertOk()
        ->assertSee($booking->reference);

    expect($booking->fresh()->status)->toBe(BookingStatus::Cancelled);
});

it('POSTing the same valid signed cancel link twice is idempotent', function () {
    $tenant = publicTenant('ardi');
    tenancy()->initialize($tenant);
    $vehicle = publicVehicle();
    $booking = Booking::factory()->forVehicle($vehicle)->create();
    tenancy()->end();

    Event::fake([BookingCancelled::class]);

    URL::forceRootUrl(tenant_url('ardi'));
    $url = URL::temporarySignedRoute(
        'public.booking.cancel',
        now()->addDay(),
        ['booking' => $booking->id],
    );

    $this->post($url)->assertOk();
    $this->post($url)->assertOk();

    expect($booking->fresh()->status)->toBe(BookingStatus::Cancelled);
    Event::assertDispatchedTimes(BookingCancelled::class, 1);
});

it('expired signed cancel link returns 404 on GET and POST', function () {
    $tenant = publicTenant('ardi');
    tenancy()->initialize($tenant);
    $vehicle = publicVehicle();
    $booking = Booking::factory()->forVehicle($vehicle)->create();
    tenancy()->end();

    URL::forceRootUrl(tenant_url('ardi'));
    $url = URL::temporarySignedRoute(
        'public.booking.cancel',
        now()->subSecond(),          // already expired
        ['booking' => $booking->id],
    );

    $this->get($url)->assertNotFound();
    $this->post($url)->assertNotFound();
});

it('tampered signed cancel link returns 404 on GET and POST', function () {
    $tenant = publicTenant('ardi');
    tenancy()->initialize($tenant);
    $vehicle = publicVehicle();
    $booking = Booking::factory()->forVehicle($vehicle)->create();
    tenancy()->end();

    URL::forceRootUrl(tenant_url('ardi'));
    // Build URL then tamper.
    $url = URL::temporarySignedRoute(
        'public.booking.cancel',
        now()->addDay(),
        ['booking' => $booking->id],
    );

    // The URL itself is for the ardi subdomain — tamper by appending a param.
    $this->get($url.'&tamper=1')->assertNotFound();
    $this->post($url.'&tamper=1')->assertNotFound();
});

it('confirmation page does not expose a self-minted cancel link', function () {
    $tenant = publicTenant('ardi');
    tenancy()->initialize($tenant);
    $vehicle = publicVehicle();
    $booking = Booking::factory()->forVehicle($vehicle)->create(['reference' => 'BK-2030-NOLINK']);
    tenancy()->end();

    $this->get(tenant_url('ardi', '/booking/BK-2030-NOLINK/confirmation'))
        ->assertOk()
        ->assertDontSee('signature=', escape: false);
});

it('signed cancel link shows a confirm page but does not cancel a Confirmed booking on GET', function () {
    // deep-audit finding 07: a Confirmed booking is now self-cancellable — the
    // wizard's "check your email to cancel" advice is pointless for anyone
    // past Pending unless this path works too.
    $tenant = publicTenant('ardi');
    tenancy()->initialize($tenant);
    $vehicle = publicVehicle();
    $booking = Booking::factory()->forVehicle($vehicle)->confirmed()->create();
    tenancy()->end();

    URL::forceRootUrl(tenant_url('ardi'));
    $url = URL::temporarySignedRoute(
        'public.booking.cancel',
        now()->addDay(),
        ['booking' => $booking->id],
    );

    $this->get($url)
        ->assertOk()
        ->assertSee($booking->reference)
        ->assertSee(__('booking.confirm_cancel_button'));

    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
});

it('signed cancel link cancels a Confirmed booking on POST', function () {
    $tenant = publicTenant('ardi');
    tenancy()->initialize($tenant);
    $vehicle = publicVehicle();
    $booking = Booking::factory()->forVehicle($vehicle)->confirmed()->create();
    tenancy()->end();

    URL::forceRootUrl(tenant_url('ardi'));
    $url = URL::temporarySignedRoute(
        'public.booking.cancel',
        now()->addDay(),
        ['booking' => $booking->id],
    );

    $this->post($url)
        ->assertOk()
        ->assertSee($booking->reference);

    expect($booking->fresh()->status)->toBe(BookingStatus::Cancelled);
});

it('signed cancel link does not cancel an Active booking on GET or POST', function () {
    $tenant = publicTenant('ardi');
    tenancy()->initialize($tenant);
    $vehicle = publicVehicle();
    $booking = Booking::factory()->forVehicle($vehicle)->active()->create();
    tenancy()->end();

    URL::forceRootUrl(tenant_url('ardi'));
    $url = URL::temporarySignedRoute(
        'public.booking.cancel',
        now()->addDay(),
        ['booking' => $booking->id],
    );

    $this->get($url)->assertOk();
    expect($booking->fresh()->status)->toBe(BookingStatus::Active);

    $this->post($url)->assertOk();
    expect($booking->fresh()->status)->toBe(BookingStatus::Active);
});

it('isSelfCancellable is true for Pending and Confirmed, false for everything else', function () {
    $tenant = publicTenant('ardi');
    tenancy()->initialize($tenant);
    $vehicle = publicVehicle();

    expect(Booking::factory()->forVehicle($vehicle)->create()->isSelfCancellable())->toBeTrue()
        ->and(Booking::factory()->forVehicle($vehicle)->confirmed()->create()->isSelfCancellable())->toBeTrue()
        ->and(Booking::factory()->forVehicle($vehicle)->active()->create()->isSelfCancellable())->toBeFalse()
        ->and(Booking::factory()->forVehicle($vehicle)->completed()->create()->isSelfCancellable())->toBeFalse()
        ->and(Booking::factory()->forVehicle($vehicle)->cancelled()->create()->isSelfCancellable())->toBeFalse();
});

// ── Language toggle ───────────────────────────────────────────────────────────

it('toggling language changes rendered strings', function () {
    $tenant = publicTenant('ardi');
    tenancy()->initialize($tenant);
    publicVehicle();
    tenancy()->end();

    // Default locale is sq — header should show 'English'.
    $this->get(tenant_url('ardi', '/vehicles'))
        ->assertSee('English');

    // Toggle to en.
    $this->post(tenant_url('ardi', '/language'), ['locale' => 'en'])
        ->assertRedirect();

    $this->get(tenant_url('ardi', '/vehicles'))
        ->assertSee('Shqip');
});
