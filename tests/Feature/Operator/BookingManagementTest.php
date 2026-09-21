<?php

use App\Enums\BookingStatus;
use App\Events\BookingCreated;
use App\Filament\Operator\Resources\Bookings\Pages\CreateBooking;
use App\Filament\Operator\Resources\Bookings\Pages\ListBookings;
use App\Filament\Operator\Widgets\AvailabilityCalendar;
use App\Models\BlockedDate;
use App\Models\Booking;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\AvailabilityService;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

afterEach(function () {
    tenancy()->end();
});

/**
 * Boot an active tenant with its operator user and a vehicle inside that tenant.
 *
 * @return array{0: Tenant, 1: User, 2: Vehicle}
 */
function bookingOperatorFor(string $domain): array
{
    $tenant = Tenant::factory()->withDomain($domain)->create();
    $operator = operatorFor($tenant);

    tenancy()->initialize($tenant);
    Filament::setCurrentPanel(Filament::getPanel('operator'));
    actingAs($operator);

    $vehicle = Vehicle::factory()->create(['daily_rate' => 50, 'weekly_rate' => null, 'monthly_rate' => null]);

    return [$tenant, $operator, $vehicle];
}

/** @return array<string, mixed> */
function manualBookingData(Vehicle $vehicle, array $overrides = []): array
{
    return array_merge([
        'vehicle_id' => $vehicle->id,
        'customer_name' => 'Walk-in Customer',
        'customer_phone' => '+38344000001',
        'customer_email' => null,
        'pickup_location' => null,
        'notes' => null,
        'start_date' => '2030-07-01 10:00',
        'end_date' => '2030-07-04 10:00',
    ], $overrides);
}

// ─── List & scope ────────────────────────────────────────────────────────────

it('operator sees their tenant bookings in the list', function () {
    [$tenant, $operator, $vehicle] = bookingOperatorFor('ardi');

    $booking = Booking::factory()->forVehicle($vehicle)->create();

    Livewire::test(ListBookings::class)
        ->assertSee($booking->reference);
});

it('operator never sees bookings from another tenant', function () {
    [$tenantA, $operatorA, $vehicleA] = bookingOperatorFor('ardi');
    $bookingA = Booking::factory()->forVehicle($vehicleA)->create();
    tenancy()->end();

    $tenantB = Tenant::factory()->withDomain('bardh')->create();
    tenancy()->initialize($tenantB);
    $operatorB = operatorFor($tenantB);
    $vehicleB = Vehicle::factory()->create(['daily_rate' => 40]);
    $bookingB = Booking::factory()->forVehicle($vehicleB)->create();
    tenancy()->end();

    // Re-initialize as tenant A and confirm isolation.
    tenancy()->initialize($tenantA);
    Filament::setCurrentPanel(Filament::getPanel('operator'));
    actingAs($operatorA);

    Livewire::test(ListBookings::class)
        ->assertSee($bookingA->reference)
        ->assertDontSee($bookingB->reference);
});

// ─── Transition actions ───────────────────────────────────────────────────────

it('confirm action is visible only for Pending and transitions to Confirmed', function () {
    [$tenant, $operator, $vehicle] = bookingOperatorFor('ardi');
    // Default factory state is Pending.
    $booking = Booking::factory()->forVehicle($vehicle)->create();

    Livewire::test(ListBookings::class)
        ->callTableAction('confirm', $booking)
        ->assertHasNoTableActionErrors();

    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
});

it('confirm action is hidden for an Active booking', function () {
    [$tenant, $operator, $vehicle] = bookingOperatorFor('ardi');
    $booking = Booking::factory()->forVehicle($vehicle)->active()->create();

    Livewire::test(ListBookings::class)
        ->assertTableActionHidden('confirm', $booking);

    expect($booking->fresh()->status)->toBe(BookingStatus::Active);
});

it('reject action transitions Pending to Cancelled', function () {
    [$tenant, $operator, $vehicle] = bookingOperatorFor('ardi');
    $booking = Booking::factory()->forVehicle($vehicle)->create();

    Livewire::test(ListBookings::class)
        ->callTableAction('reject', $booking);

    expect($booking->fresh()->status)->toBe(BookingStatus::Cancelled)
        ->and($booking->fresh()->cancellation_reason)->toBeNull();
});

it('reject action records an optional reason', function () {
    [$tenant, $operator, $vehicle] = bookingOperatorFor('ardi');
    $booking = Booking::factory()->forVehicle($vehicle)->create();

    Livewire::test(ListBookings::class)
        ->callTableAction('reject', $booking, data: [
            'reason' => 'No valid driving license provided.',
        ]);

    expect($booking->fresh()->cancellation_reason)->toBe('No valid driving license provided.');
});

it('mark_active action transitions Confirmed to Active with odometer', function () {
    [$tenant, $operator, $vehicle] = bookingOperatorFor('ardi');
    $booking = Booking::factory()->forVehicle($vehicle)->confirmed()->create();

    Livewire::test(ListBookings::class)
        ->callTableAction('mark_active', $booking, data: [
            'started_at' => now()->toDateTimeString(),
            'start_odometer' => 12500,
        ]);

    $fresh = $booking->fresh();
    expect($fresh->status)->toBe(BookingStatus::Active)
        ->and($fresh->start_odometer)->toBe(12500)
        ->and($fresh->started_at)->not->toBeNull();
});

it('complete action transitions Active to Completed with odometer', function () {
    [$tenant, $operator, $vehicle] = bookingOperatorFor('ardi');
    $booking = Booking::factory()->forVehicle($vehicle)->active()->create();

    Livewire::test(ListBookings::class)
        ->callTableAction('complete', $booking, data: [
            'completed_at' => now()->toDateTimeString(),
            'end_odometer' => 12750,
        ]);

    $fresh = $booking->fresh();
    expect($fresh->status)->toBe(BookingStatus::Completed)
        ->and($fresh->end_odometer)->toBe(12750)
        ->and($fresh->completed_at)->not->toBeNull();
});

it('cancel action transitions a Confirmed booking to Cancelled', function () {
    [$tenant, $operator, $vehicle] = bookingOperatorFor('ardi');
    $booking = Booking::factory()->forVehicle($vehicle)->confirmed()->create();

    Livewire::test(ListBookings::class)
        ->callTableAction('cancel', $booking);

    expect($booking->fresh()->status)->toBe(BookingStatus::Cancelled)
        ->and($booking->fresh()->cancellation_reason)->toBeNull();
});

it('cancel action records an optional reason', function () {
    [$tenant, $operator, $vehicle] = bookingOperatorFor('ardi');
    $booking = Booking::factory()->forVehicle($vehicle)->confirmed()->create();

    Livewire::test(ListBookings::class)
        ->callTableAction('cancel', $booking, data: [
            'reason' => 'Vehicle was in an accident and is unavailable.',
        ]);

    expect($booking->fresh()->cancellation_reason)->toBe('Vehicle was in an accident and is unavailable.');
});

it('cancel action is not visible for Completed bookings', function () {
    [$tenant, $operator, $vehicle] = bookingOperatorFor('ardi');
    $booking = Booking::factory()->forVehicle($vehicle)->completed()->create();

    Livewire::test(ListBookings::class)
        ->assertTableActionHidden('cancel', $booking);
});

// ─── Move (deep-audit finding 08) ──────────────────────────────────────────────

it('move action is visible for Pending and Confirmed bookings', function () {
    [$tenant, $operator, $vehicle] = bookingOperatorFor('ardi');
    $pending = Booking::factory()->forVehicle($vehicle)->create();
    $confirmed = Booking::factory()->forVehicle($vehicle)->confirmed()->create();

    Livewire::test(ListBookings::class)
        ->assertTableActionVisible('move', $pending)
        ->assertTableActionVisible('move', $confirmed);
});

it('move action is hidden for Active, Completed and Cancelled bookings', function () {
    [$tenant, $operator, $vehicle] = bookingOperatorFor('ardi');
    $active = Booking::factory()->forVehicle($vehicle)->active()->create();
    $completed = Booking::factory()->forVehicle($vehicle)->completed()->create();
    $cancelled = Booking::factory()->forVehicle($vehicle)->cancelled()->create();

    Livewire::test(ListBookings::class)
        ->assertTableActionHidden('move', $active)
        ->assertTableActionHidden('move', $completed)
        ->assertTableActionHidden('move', $cancelled);
});

it('move action updates the booking to the new vehicle and dates', function () {
    [$tenant, $operator, $vehicle] = bookingOperatorFor('ardi');
    $booking = Booking::factory()->forVehicle($vehicle)->confirmed()->create([
        'start_date' => '2030-07-01',
        'end_date' => '2030-07-04',
    ]);
    $otherVehicle = Vehicle::factory()->create(['daily_rate' => 60, 'weekly_rate' => null, 'monthly_rate' => null]);

    Livewire::test(ListBookings::class)
        ->callTableAction('move', $booking, data: [
            'vehicle_id' => $otherVehicle->id,
            'start_date' => '2030-08-01',
            'end_date' => '2030-08-04',
        ])
        ->assertHasNoTableActionErrors();

    expect($booking->fresh()->vehicle_id)->toBe($otherVehicle->id)
        ->and($booking->fresh()->start_date->toDateString())->toBe('2030-08-01')
        ->and($booking->fresh()->previous_vehicle_id)->toBe($vehicle->id)
        ->and($booking->fresh()->moved_at)->not->toBeNull();
});

it('move action onto a taken slot shows a notification and leaves the booking untouched', function () {
    [$tenant, $operator, $vehicle] = bookingOperatorFor('ardi');
    $booking = Booking::factory()->forVehicle($vehicle)->confirmed()->create([
        'start_date' => '2030-07-01',
        'end_date' => '2030-07-04',
    ]);
    Booking::factory()->forVehicle($vehicle)->confirmed()->create([
        'start_date' => '2030-09-01',
        'end_date' => '2030-09-05',
    ]);

    Livewire::test(ListBookings::class)
        ->callTableAction('move', $booking, data: [
            'vehicle_id' => $vehicle->id,
            'start_date' => '2030-09-02',
            'end_date' => '2030-09-04',
        ])
        ->assertNotified();

    expect($booking->fresh()->start_date->toDateString())->toBe('2030-07-01')
        ->and($booking->fresh()->moved_at)->toBeNull();
});

// ─── Manual booking ───────────────────────────────────────────────────────────

it('manual booking via create page lands Confirmed with computed totals', function () {
    Event::fake([BookingCreated::class]);

    [$tenant, $operator, $vehicle] = bookingOperatorFor('ardi');

    Livewire::test(CreateBooking::class)
        ->fillForm(manualBookingData($vehicle))
        ->call('create')
        ->assertHasNoFormErrors();

    $booking = Booking::latest()->first();
    expect($booking->status)->toBe(BookingStatus::Confirmed)
        ->and((float) $booking->total)->toBeGreaterThan(0)
        ->and($booking->tenant_id)->toBe($tenant->id);

    Event::assertNotDispatched(BookingCreated::class);
});

it('manual booking onto a taken slot fails with a notification and creates no row', function () {
    [$tenant, $operator, $vehicle] = bookingOperatorFor('ardi');

    // Pre-fill the slot.
    Booking::factory()->forVehicle($vehicle)->confirmed()->create([
        'start_date' => '2030-07-01',
        'end_date' => '2030-07-04',
    ]);

    $before = Booking::count();

    Livewire::test(CreateBooking::class)
        ->fillForm(manualBookingData($vehicle))
        ->call('create');

    expect(Booking::count())->toBe($before);
});

it('manual booking may start in the past — the walk-in entered two hours late', function () {
    [$tenant, $operator, $vehicle] = bookingOperatorFor('ardi');

    Livewire::test(CreateBooking::class)
        ->fillForm(manualBookingData($vehicle, [
            'start_date' => today()->subDay()->setTime(9, 0)->toDateTimeString(),
            'end_date' => today()->addDays(2)->setTime(9, 0)->toDateTimeString(),
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Booking::latest()->first()->status)->toBe(BookingStatus::Confirmed);
});

it('manual booking longer than the maximum creates no row', function () {
    [$tenant, $operator, $vehicle] = bookingOperatorFor('ardi');
    $before = Booking::count();
    $start = today()->addDay();

    Livewire::test(CreateBooking::class)
        ->fillForm(manualBookingData($vehicle, [
            'start_date' => $start->toDateTimeString(),
            'end_date' => $start->copy()->addDays(config('bookings.max_rental_days') + 1)->toDateTimeString(),
        ]))
        ->call('create');

    expect(Booking::count())->toBe($before);
});

// ─── Blocked dates / calendar ─────────────────────────────────────────────────

it('creating a blocked date makes that window unavailable', function () {
    [$tenant, $operator, $vehicle] = bookingOperatorFor('ardi');

    BlockedDate::create([
        'vehicle_id' => $vehicle->id,
        'start_date' => '2030-08-01',
        'end_date' => '2030-08-05',
    ]);

    $available = app(AvailabilityService::class)->isAvailable(
        $vehicle,
        Carbon::parse('2030-08-02'),
        Carbon::parse('2030-08-04'),
    );

    expect($available)->toBeFalse();
});

it('removing a blocked date frees the window', function () {
    [$tenant, $operator, $vehicle] = bookingOperatorFor('ardi');

    $block = BlockedDate::create([
        'vehicle_id' => $vehicle->id,
        'start_date' => '2030-08-01',
        'end_date' => '2030-08-05',
    ]);

    $block->delete();

    $available = app(AvailabilityService::class)->isAvailable(
        $vehicle,
        Carbon::parse('2030-08-02'),
        Carbon::parse('2030-08-04'),
    );

    expect($available)->toBeTrue();
});

it('blocked dates from tenant A are invisible in tenant B context', function () {
    [$tenantA, $operatorA, $vehicleA] = bookingOperatorFor('ardi');

    BlockedDate::create([
        'vehicle_id' => $vehicleA->id,
        'start_date' => '2030-09-01',
        'end_date' => '2030-09-05',
    ]);

    tenancy()->end();

    $tenantB = Tenant::factory()->withDomain('bardh')->create();
    tenancy()->initialize($tenantB);

    expect(BlockedDate::count())->toBe(0);
});

// ─── Availability calendar: block dates (M8) ─────────────────────────────────

it('blocks the exact date range picked in the block-dates form', function () {
    [, , $vehicle] = bookingOperatorFor('caldates');

    Livewire::test(AvailabilityCalendar::class)
        ->callAction('create', data: [
            'vehicle_id' => $vehicle->id,
            'start_date' => '2030-08-01',
            'end_date' => '2030-08-05',
            'reason' => 'maintenance',
        ])
        ->assertHasNoActionErrors();

    $block = BlockedDate::query()->where('vehicle_id', $vehicle->id)->firstOrFail();

    expect($block->start_date->toDateString())->toBe('2030-08-01')
        ->and($block->end_date->toDateString())->toBe('2030-08-05');
});

it('requires both dates and rejects end before start when blocking dates', function () {
    [, , $vehicle] = bookingOperatorFor('calinvalid');

    Livewire::test(AvailabilityCalendar::class)
        ->callAction('create', data: [
            'vehicle_id' => $vehicle->id,
        ])
        ->assertHasActionErrors(['start_date', 'end_date']);

    Livewire::test(AvailabilityCalendar::class)
        ->callAction('create', data: [
            'vehicle_id' => $vehicle->id,
            'start_date' => '2030-08-05',
            'end_date' => '2030-08-01',
        ])
        ->assertHasActionErrors(['end_date']);

    expect(BlockedDate::query()->count())->toBe(0);
});

// ─── Availability calendar: block dates reject an overlapping booking ─────────

it('rejects blocking a range that overlaps an existing booking', function () {
    [, , $vehicle] = bookingOperatorFor('calbookoverlap');

    Booking::factory()->forVehicle($vehicle)->confirmed()->create([
        'start_date' => '2030-09-10',
        'end_date' => '2030-09-15',
    ]);

    Livewire::test(AvailabilityCalendar::class)
        ->callAction('create', data: [
            'vehicle_id' => $vehicle->id,
            'start_date' => '2030-09-12',
            'end_date' => '2030-09-18',
            'reason' => 'maintenance',
        ])
        ->assertHasActionErrors(['end_date']);

    expect(BlockedDate::query()->count())->toBe(0);
});

it('allows blocking a range that starts exactly when a booking ends (no overlap)', function () {
    [, , $vehicle] = bookingOperatorFor('calbookadjacent');

    Booking::factory()->forVehicle($vehicle)->confirmed()->create([
        'start_date' => '2030-09-10',
        'end_date' => '2030-09-15',
    ]);

    Livewire::test(AvailabilityCalendar::class)
        ->callAction('create', data: [
            'vehicle_id' => $vehicle->id,
            'start_date' => '2030-09-15',
            'end_date' => '2030-09-20',
            'reason' => 'maintenance',
        ])
        ->assertHasNoActionErrors();

    expect(BlockedDate::query()->where('vehicle_id', $vehicle->id)->count())->toBe(1);
});

it('allows blocking over a cancelled booking (freed range)', function () {
    [, , $vehicle] = bookingOperatorFor('calbookcancelled');

    Booking::factory()->forVehicle($vehicle)->cancelled()->create([
        'start_date' => '2030-09-10',
        'end_date' => '2030-09-15',
    ]);

    Livewire::test(AvailabilityCalendar::class)
        ->callAction('create', data: [
            'vehicle_id' => $vehicle->id,
            'start_date' => '2030-09-11',
            'end_date' => '2030-09-14',
            'reason' => 'maintenance',
        ])
        ->assertHasNoActionErrors();

    expect(BlockedDate::query()->where('vehicle_id', $vehicle->id)->count())->toBe(1);
});

// ─── Availability calendar: block dates reject a cross-tenant vehicle (M6) ────

it('rejects blocking a vehicle that belongs to another tenant', function () {
    $tenantB = Tenant::factory()->withDomain('calcrossb')->create();
    tenancy()->initialize($tenantB);
    $vehicleB = Vehicle::factory()->create();
    tenancy()->end();

    [, , $vehicleA] = bookingOperatorFor('calcross');

    Livewire::test(AvailabilityCalendar::class)
        ->callAction('create', data: [
            'vehicle_id' => $vehicleB->id,
            'start_date' => '2030-08-01',
            'end_date' => '2030-08-05',
        ])
        ->assertHasActionErrors(['vehicle_id']);

    expect(BlockedDate::query()->count())->toBe(0);

    Livewire::test(AvailabilityCalendar::class)
        ->callAction('create', data: [
            'vehicle_id' => $vehicleA->id,
            'start_date' => '2030-08-01',
            'end_date' => '2030-08-05',
        ])
        ->assertHasNoActionErrors();

    expect(BlockedDate::query()->where('vehicle_id', $vehicleA->id)->count())->toBe(1);
});

// ─── Availability calendar: UX rebuild ───────────────────────────────────────

it('filters calendar events by the selected vehicle', function () {
    [, , $vehicleA] = bookingOperatorFor('calfilter');
    $vehicleB = Vehicle::factory()->create(['daily_rate' => 40]);

    Booking::factory()->forVehicle($vehicleA)->confirmed()->create([
        'start_date' => '2030-09-05', 'end_date' => '2030-09-08',
    ]);
    Booking::factory()->forVehicle($vehicleB)->confirmed()->create([
        'start_date' => '2030-09-10', 'end_date' => '2030-09-12',
    ]);

    $info = ['start' => '2030-09-01', 'end' => '2030-09-30', 'timezone' => 'UTC'];

    $component = Livewire::test(AvailabilityCalendar::class);

    expect($component->instance()->fetchEvents($info))->toHaveCount(2);

    $component->set('vehicleFilter', $vehicleA->id);

    $filtered = $component->instance()->fetchEvents($info);

    expect($filtered)->toHaveCount(1)
        ->and($filtered[0]['title'])->toContain($vehicleA->name);
});

it('gives booking events a URL to the booking view page', function () {
    [, , $vehicle] = bookingOperatorFor('calurl');

    $booking = Booking::factory()->forVehicle($vehicle)->confirmed()->create([
        'start_date' => '2030-09-05', 'end_date' => '2030-09-08',
    ]);

    $events = Livewire::test(AvailabilityCalendar::class)->instance()->fetchEvents([
        'start' => '2030-09-01', 'end' => '2030-09-30', 'timezone' => 'UTC',
    ]);

    expect($events[0]['url'])->toContain("/dashboard/bookings/{$booking->id}");
});

it('removes a blocked date only through the confirmed removeBlock action', function () {
    [, , $vehicle] = bookingOperatorFor('calremove');

    $block = BlockedDate::create([
        'vehicle_id' => $vehicle->id,
        'start_date' => '2030-09-01',
        'end_date' => '2030-09-03',
        'reason' => 'maintenance',
    ]);

    $component = Livewire::test(AvailabilityCalendar::class);

    // A bare event click only mounts the confirmation modal — nothing deleted.
    $component->call('onEventClick', [
        'extendedProps' => ['type' => 'block', 'block_id' => $block->id],
    ]);
    expect(BlockedDate::query()->count())->toBe(1);

    // Confirming the mounted action performs the deletion.
    $component->call('callMountedAction');
    expect(BlockedDate::query()->count())->toBe(0);
});

// ─── Availability calendar: block actions are owner-only (M4) ─────────────────

/**
 * Boot an active tenant with a STAFF user (not the owner) and a vehicle inside it.
 *
 * @return array{0: Tenant, 1: User, 2: Vehicle}
 */
function bookingStaffFor(string $domain): array
{
    $tenant = Tenant::factory()->withDomain($domain)->create();
    $staff = User::factory()->staff()->create(['tenant_id' => $tenant->id]);

    tenancy()->initialize($tenant);
    Filament::setCurrentPanel(Filament::getPanel('operator'));
    actingAs($staff);

    $vehicle = Vehicle::factory()->create(['daily_rate' => 50, 'weekly_rate' => null, 'monthly_rate' => null]);

    return [$tenant, $staff, $vehicle];
}

it('shows the block-dates action to the owner', function () {
    bookingOperatorFor('m4owner');

    Livewire::test(AvailabilityCalendar::class)->assertActionVisible('create');
});

it('hides the block-dates action from staff', function () {
    bookingStaffFor('m4staff');

    Livewire::test(AvailabilityCalendar::class)->assertActionHidden('create');
});

it('ignores a staff drag-select — no block modal, no block created', function () {
    bookingStaffFor('m4staffselect');

    Livewire::test(AvailabilityCalendar::class)
        ->call('onDateSelect', '2030-08-01', '2030-08-02', true, null, null)
        ->assertHasNoActionErrors();

    expect(BlockedDate::query()->count())->toBe(0);
});

it('does not let staff delete a block via event click', function () {
    [, , $vehicle] = bookingStaffFor('m4staffremove');

    $block = BlockedDate::create([
        'vehicle_id' => $vehicle->id,
        'start_date' => '2030-09-01',
        'end_date' => '2030-09-03',
        'reason' => 'maintenance',
    ]);

    $component = Livewire::test(AvailabilityCalendar::class);

    // Block click must not mount the removeBlock action for staff…
    $component->call('onEventClick', [
        'extendedProps' => ['type' => 'block', 'block_id' => $block->id],
    ]);
    // …so confirming a (non-existent) mounted action deletes nothing.
    $component->call('callMountedAction');

    expect(BlockedDate::query()->count())->toBe(1);
});

it('still shows the calendar read view to staff', function () {
    [, , $vehicle] = bookingStaffFor('m4staffread');

    Booking::factory()->forVehicle($vehicle)->confirmed()->create([
        'start_date' => '2030-09-05', 'end_date' => '2030-09-08',
    ]);

    $events = Livewire::test(AvailabilityCalendar::class)->instance()->fetchEvents([
        'start' => '2030-09-01', 'end' => '2030-09-30', 'timezone' => 'UTC',
    ]);

    expect($events)->toHaveCount(1);
});
