<?php

use App\Enums\BookingStatus;
use App\Enums\VehicleStatus;
use App\Events\BookingCancelled;
use App\Events\BookingConfirmed;
use App\Events\BookingCreated;
use App\Events\BookingMoved;
use App\Events\BookingRejected;
use App\Exceptions\InvalidBookingWindowException;
use App\Exceptions\VehicleNotAvailableException;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\Vehicle;
use App\Services\BookingService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;

covers(BookingService::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    tenancy()->initialize($this->tenant);
    $this->vehicle = Vehicle::factory()->create(['daily_rate' => 50, 'weekly_rate' => null, 'monthly_rate' => null]);
    $this->service = app(BookingService::class);
});

afterEach(fn () => tenancy()->end());

/** @return array<string, mixed> */
function bookingData(Vehicle $vehicle, array $overrides = []): array
{
    return array_merge([
        'vehicle_id' => $vehicle->id,
        'customer_name' => 'Test Customer',
        'customer_phone' => '+38344000000',
        'start_date' => '2030-06-01',
        'end_date' => '2030-06-04',
    ], $overrides);
}

it('creates a pending booking with a BK reference and correct totals', function () {
    $booking = $this->service->create(bookingData($this->vehicle));

    expect($booking->status)->toBe(BookingStatus::Pending)
        ->and($booking->reference)->toMatch('/^BK-\d{4}-[0-9A-HJKMNP-TV-Z]{8}$/')
        ->and($booking->tenant_id)->toBe($this->tenant->id)
        ->and((float) $booking->total)->toBe(150.0); // 3 days * 50
});

it('throws VehicleNotAvailableException for an already booked slot', function () {
    $this->service->create(bookingData($this->vehicle));

    $this->service->create(bookingData($this->vehicle));
})->throws(VehicleNotAvailableException::class);

it('throws VehicleNotAvailableException on the re-check under lock', function () {
    // Simulate the race condition: first booking fills the slot.
    Booking::factory()->forVehicle($this->vehicle)->confirmed()->create([
        'start_date' => '2030-06-01',
        'end_date' => '2030-06-04',
    ]);

    // Second create must fail even though no lock is truly contested in SQLite.
    // Documents: true parallel FOR UPDATE guarantee is Postgres-level.
    $this->service->create(bookingData($this->vehicle));
})->throws(VehicleNotAvailableException::class);

it('throws VehicleNotAvailableException, not InvalidArgumentException, for inverted dates on create', function () {
    $this->service->create(bookingData($this->vehicle, [
        'start_date' => '2030-06-04',
        'end_date' => '2030-06-01',
    ]));
})->throws(VehicleNotAvailableException::class);

it('throws VehicleNotAvailableException, not InvalidArgumentException, for inverted dates on createManual', function () {
    $this->service->createManual(bookingData($this->vehicle, [
        'start_date' => '2030-06-04',
        'end_date' => '2030-06-01',
    ]));
})->throws(VehicleNotAvailableException::class);

// ─── Booking window (deep-audit finding 02) ──────────────────────────────────

it('rejects a customer booking that starts in the past', function () {
    expect(fn () => $this->service->create(bookingData($this->vehicle, [
        'start_date' => today()->subDay()->toDateString(),
        'end_date' => today()->addDays(3)->toDateString(),
    ])))->toThrow(InvalidBookingWindowException::class);

    expect(Booking::query()->count())->toBe(0);
});

it('accepts a customer booking starting today', function () {
    // today() is the earliest bookable start — the boundary of the past-date guard.
    $booking = $this->service->create(bookingData($this->vehicle, [
        'start_date' => today()->toDateString(),
        'end_date' => today()->addDays(3)->toDateString(),
    ]));

    expect($booking->status)->toBe(BookingStatus::Pending);
});

it('rejects a rental longer than the configured maximum', function () {
    $max = config('bookings.max_rental_days');

    expect(fn () => $this->service->create(bookingData($this->vehicle, [
        'start_date' => today()->addDay()->toDateString(),
        'end_date' => today()->addDay()->addDays($max + 1)->toDateString(),
    ])))->toThrow(InvalidBookingWindowException::class);

    expect(Booking::query()->count())->toBe(0);
});

it('accepts a rental of exactly the configured maximum', function () {
    $max = config('bookings.max_rental_days');

    $booking = $this->service->create(bookingData($this->vehicle, [
        'start_date' => today()->addDay()->toDateString(),
        'end_date' => today()->addDay()->addDays($max)->toDateString(),
    ]));

    expect($booking->status)->toBe(BookingStatus::Pending);
});

it('lets an operator record a manual booking that already started', function () {
    // The walk-in who drove off at 09:00 and is entered at 11:00 — the past-date
    // floor is deliberately customer-only.
    $booking = $this->service->createManual(bookingData($this->vehicle, [
        'start_date' => today()->subDay()->toDateString(),
        'end_date' => today()->addDays(3)->toDateString(),
    ]));

    expect($booking->status)->toBe(BookingStatus::Confirmed);
});

it('still caps the duration on the manual booking path', function () {
    $max = config('bookings.max_rental_days');

    expect(fn () => $this->service->createManual(bookingData($this->vehicle, [
        'start_date' => today()->toDateString(),
        'end_date' => today()->addDays($max + 1)->toDateString(),
    ])))->toThrow(InvalidBookingWindowException::class);

    expect(Booking::query()->count())->toBe(0);
});

it('measures the cap the way the price measures it — part-days round up', function () {
    $max = config('bookings.max_rental_days');

    // Exactly max days plus two hours prices as max+1 days, so it must fail too.
    expect(fn () => $this->service->create(bookingData($this->vehicle, [
        'start_date' => today()->addDay()->toDateTimeString(),
        'end_date' => today()->addDay()->addDays($max)->addHours(2)->toDateTimeString(),
    ])))->toThrow(InvalidBookingWindowException::class);
});

// ─── Vehicle bookability (deep-audit finding 05) ──────────────────────────────

it('rejects a customer booking on a vehicle under maintenance', function () {
    $this->vehicle->update(['status' => VehicleStatus::UnderMaintenance]);

    expect(fn () => $this->service->create(bookingData($this->vehicle)))
        ->toThrow(VehicleNotAvailableException::class);

    expect(Booking::query()->count())->toBe(0);
});

it('rejects a customer booking on a vehicle marked Booked', function () {
    $this->vehicle->update(['status' => VehicleStatus::Booked]);

    expect(fn () => $this->service->create(bookingData($this->vehicle)))
        ->toThrow(VehicleNotAvailableException::class);

    expect(Booking::query()->count())->toBe(0);
});

it('rejects a customer booking on an unlisted vehicle', function () {
    $this->vehicle->update(['is_public' => false]);

    expect(fn () => $this->service->create(bookingData($this->vehicle)))
        ->toThrow(VehicleNotAvailableException::class);

    expect(Booking::query()->count())->toBe(0);
});

it('rejects a manual booking on a vehicle under maintenance too', function () {
    // status is a real-world rentability concern, not a storefront-visibility
    // one — it blocks both create paths, unlike is_public below.
    $this->vehicle->update(['status' => VehicleStatus::UnderMaintenance]);

    expect(fn () => $this->service->createManual(bookingData($this->vehicle)))
        ->toThrow(VehicleNotAvailableException::class);

    expect(Booking::query()->count())->toBe(0);
});

it('lets an operator record a manual booking on an unlisted vehicle', function () {
    // The point of the manual path: a VIP car or an off-storefront arrangement
    // an operator deliberately keeps unlisted must still be phone-bookable.
    $this->vehicle->update(['is_public' => false]);

    $booking = $this->service->createManual(bookingData($this->vehicle));

    expect($booking->status)->toBe(BookingStatus::Confirmed);
});

it('fires BookingCreated event on successful create', function () {
    // Only fake BookingCreated — faking all events blocks Eloquent model events
    // (creating/created) that BelongsToTenant uses to auto-fill tenant_id.
    Event::fake([BookingCreated::class]);

    $this->service->create(bookingData($this->vehicle));

    Event::assertDispatched(BookingCreated::class);
});

it('transitions pending → confirmed', function () {
    $booking = $this->service->create(bookingData($this->vehicle));
    $this->service->confirm($booking);

    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
});

it('transitions pending → cancelled via reject', function () {
    $booking = $this->service->create(bookingData($this->vehicle));
    $this->service->reject($booking);

    expect($booking->fresh()->status)->toBe(BookingStatus::Cancelled);
});

it('transitions confirmed → active and sets started_at', function () {
    $booking = $this->service->create(bookingData($this->vehicle));
    $this->service->confirm($booking);
    $this->service->markActive($booking);

    expect($booking->fresh()->status)->toBe(BookingStatus::Active)
        ->and($booking->fresh()->started_at)->not->toBeNull();
});

it('transitions active → completed and sets completed_at', function () {
    $booking = $this->service->create(bookingData($this->vehicle));
    $this->service->confirm($booking);
    $this->service->markActive($booking);
    $this->service->complete($booking);

    expect($booking->fresh()->status)->toBe(BookingStatus::Completed)
        ->and($booking->fresh()->completed_at)->not->toBeNull();
});

it('fires BookingConfirmed event on confirm', function () {
    Event::fake([BookingConfirmed::class]);

    $booking = $this->service->create(bookingData($this->vehicle));
    $this->service->confirm($booking);

    Event::assertDispatched(BookingConfirmed::class);
});

it('fires BookingRejected event on reject', function () {
    Event::fake([BookingRejected::class]);

    $booking = $this->service->create(bookingData($this->vehicle));
    $this->service->reject($booking);

    Event::assertDispatched(BookingRejected::class);
});

it('stores a rejection reason when given and leaves it null when not', function () {
    $withReason = $this->service->create(bookingData($this->vehicle));
    $this->service->reject($withReason, 'Vehicle needs urgent service');

    $withoutReason = $this->service->create(bookingData($this->vehicle, [
        'start_date' => '2030-03-01',
        'end_date' => '2030-03-05',
    ]));
    $this->service->reject($withoutReason);

    expect($withReason->fresh()->cancellation_reason)->toBe('Vehicle needs urgent service')
        ->and($withoutReason->fresh()->cancellation_reason)->toBeNull();
});

it('stores a cancellation reason when given', function () {
    $booking = $this->service->create(bookingData($this->vehicle));
    $this->service->cancel($booking, 'customer', 'Changed my plans');

    expect($booking->fresh()->cancellation_reason)->toBe('Changed my plans');
});

it('honours an explicit started_at and odometer on markActive', function () {
    $booking = $this->service->create(bookingData($this->vehicle));
    $this->service->confirm($booking);
    $this->service->markActive($booking, Carbon::parse('2030-01-02 08:30'), 12_345);

    expect($booking->fresh()->started_at->toDateTimeString())->toBe('2030-01-02 08:30:00')
        ->and($booking->fresh()->start_odometer)->toBe(12_345);
});

it('honours an explicit completed_at and odometer on complete', function () {
    $booking = $this->service->create(bookingData($this->vehicle));
    $this->service->confirm($booking);
    $this->service->markActive($booking);
    $this->service->complete($booking, Carbon::parse('2030-01-09 17:45'), 12_900);

    expect($booking->fresh()->completed_at->toDateTimeString())->toBe('2030-01-09 17:45:00')
        ->and($booking->fresh()->end_odometer)->toBe(12_900);
});

it('leaves the odometer null when none is given', function () {
    $booking = $this->service->create(bookingData($this->vehicle));
    $this->service->confirm($booking);
    $this->service->markActive($booking);

    expect($booking->fresh()->start_odometer)->toBeNull();
});

it('throws on illegal transition complete from pending', function () {
    $booking = $this->service->create(bookingData($this->vehicle));

    $this->service->complete($booking);
})->throws(InvalidArgumentException::class);

it('throws when cancelling a completed booking', function () {
    $booking = $this->service->create(bookingData($this->vehicle));
    $this->service->confirm($booking);
    $this->service->markActive($booking);
    $this->service->complete($booking);

    $this->service->cancel($booking->fresh());
})->throws(InvalidArgumentException::class);

it('moves a pending booking to new dates on the same vehicle, keeping the reference and re-pricing', function () {
    $booking = $this->service->create(bookingData($this->vehicle));
    $reference = $booking->reference;

    $moved = $this->service->move($booking, [
        'vehicle_id' => $this->vehicle->id,
        'start_date' => '2030-07-01',
        'end_date' => '2030-07-05',
    ]);

    expect($moved->reference)->toBe($reference)
        ->and($moved->start_date->toDateString())->toBe('2030-07-01')
        ->and($moved->end_date->toDateString())->toBe('2030-07-05')
        ->and((float) $moved->total)->toBe(200.0) // 4 days * 50
        ->and($moved->previous_vehicle_id)->toBe($this->vehicle->id)
        ->and($moved->previous_start_date->toDateString())->toBe('2030-06-01')
        ->and($moved->previous_end_date->toDateString())->toBe('2030-06-04')
        ->and($moved->moved_at)->not->toBeNull();
});

it('moves a booking to a different vehicle', function () {
    $booking = $this->service->create(bookingData($this->vehicle));
    $otherVehicle = Vehicle::factory()->create(['daily_rate' => 80, 'weekly_rate' => null, 'monthly_rate' => null]);

    $moved = $this->service->move($booking, [
        'vehicle_id' => $otherVehicle->id,
        'start_date' => '2030-06-01',
        'end_date' => '2030-06-04',
    ]);

    expect($moved->vehicle_id)->toBe($otherVehicle->id)
        ->and($moved->previous_vehicle_id)->toBe($this->vehicle->id)
        ->and((float) $moved->total)->toBe(240.0); // 3 days * 80
});

it('throws VehicleNotAvailableException when the new window is already taken', function () {
    $booking = $this->service->create(bookingData($this->vehicle));
    Booking::factory()->forVehicle($this->vehicle)->confirmed()->create([
        'start_date' => '2030-08-01',
        'end_date' => '2030-08-05',
    ]);

    $this->service->move($booking, [
        'vehicle_id' => $this->vehicle->id,
        'start_date' => '2030-08-02',
        'end_date' => '2030-08-04',
    ]);
})->throws(VehicleNotAvailableException::class);

it('does not false-reject a move that overlaps the booking\'s own current window', function () {
    // Proves the excludeBookingId fix: without it, the booking's own current row
    // would always be found as a conflict against itself.
    $booking = $this->service->create(bookingData($this->vehicle));

    $moved = $this->service->move($booking, [
        'vehicle_id' => $this->vehicle->id,
        'start_date' => '2030-06-02', // overlaps the booking's own 06-01..06-04 window
        'end_date' => '2030-06-06',
    ]);

    expect($moved->start_date->toDateString())->toBe('2030-06-02');
});

it('throws when moving a completed booking', function () {
    $booking = $this->service->create(bookingData($this->vehicle));
    $this->service->confirm($booking);
    $this->service->markActive($booking);
    $this->service->complete($booking);

    $this->service->move($booking->fresh(), [
        'vehicle_id' => $this->vehicle->id,
        'start_date' => '2030-07-01',
        'end_date' => '2030-07-05',
    ]);
})->throws(InvalidArgumentException::class);

it('throws when moving a cancelled booking', function () {
    $booking = $this->service->create(bookingData($this->vehicle));
    $this->service->cancel($booking);

    $this->service->move($booking->fresh(), [
        'vehicle_id' => $this->vehicle->id,
        'start_date' => '2030-07-01',
        'end_date' => '2030-07-05',
    ]);
})->throws(InvalidArgumentException::class);

it('fires BookingMoved event on move', function () {
    Event::fake([BookingMoved::class]);

    $booking = $this->service->create(bookingData($this->vehicle));
    $this->service->move($booking, [
        'vehicle_id' => $this->vehicle->id,
        'start_date' => '2030-07-01',
        'end_date' => '2030-07-05',
    ]);

    Event::assertDispatched(BookingMoved::class);
});

it('cancelling an already-cancelled booking is a no-op and does not redispatch BookingCancelled', function () {
    Event::fake([BookingCancelled::class]);

    $booking = $this->service->create(bookingData($this->vehicle));
    $this->service->cancel($booking);
    $this->service->cancel($booking->fresh());

    expect($booking->fresh()->status)->toBe(BookingStatus::Cancelled);
    Event::assertDispatchedTimes(BookingCancelled::class, 1);
});

it('booking in tenant A is invisible from tenant B context', function () {
    $this->service->create(bookingData($this->vehicle));
    tenancy()->end();

    $tenantB = Tenant::factory()->create();
    tenancy()->initialize($tenantB);

    expect(Booking::count())->toBe(0);
});

it('rejects a concurrent conflicting transition via the status-guarded update', function () {
    $booking = $this->service->create(bookingData($this->vehicle));

    // Second operator holds a stale copy that still reads Pending.
    $stale = Booking::query()->findOrFail($booking->id);

    $this->service->confirm($booking);

    Event::fake();

    expect(fn () => $this->service->reject($stale))
        ->toThrow(InvalidArgumentException::class);

    expect($stale->refresh()->status)->toBe(BookingStatus::Confirmed);
    Event::assertNotDispatched(BookingRejected::class);
});

it('recovers from a concurrent unique-constraint conflict on customer phone without crashing', function () {
    // resolveCustomer()'s Customer::firstOrCreate() delegates to Eloquent's
    // createOrFirst(), which has no upfront existence check — it always attempts
    // the insert first. Pre-creating the row forces that insert to collide with
    // the [tenant_id, phone] unique key, exercising the exact
    // catch-UniqueConstraintViolationException-and-refetch path a genuine
    // concurrent request would hit (L2 in docs/consolidated-audit-report.md).
    $existing = Customer::factory()->create(['phone' => '+38344111111']);

    $resolved = Customer::query()->createOrFirst(
        ['phone' => '+38344111111'],
        ['name' => 'Late Arrival', 'email' => null],
    );

    expect($resolved->is($existing))->toBeTrue()
        ->and(Customer::query()->where('phone', '+38344111111')->count())->toBe(1);
});

it('reuses an existing customer by phone when booking a different vehicle, without duplicating', function () {
    $existing = Customer::factory()->create(['phone' => '+38344222222']);
    $secondVehicle = Vehicle::factory()->create(['daily_rate' => 40, 'weekly_rate' => null, 'monthly_rate' => null]);

    $booking = $this->service->create(bookingData($secondVehicle, ['customer_phone' => '+38344222222']));

    expect($booking->customer_id)->toBe($existing->id)
        ->and(Customer::query()->count())->toBe(1);
});

it('writes status and timestamp in one atomic update on markActive', function () {
    $booking = $this->service->create(bookingData($this->vehicle));
    $this->service->confirm($booking);

    $this->service->markActive($booking, startOdometer: 12345);

    $fresh = Booking::query()->findOrFail($booking->id);

    expect($fresh->status)->toBe(BookingStatus::Active)
        ->and($fresh->started_at)->not->toBeNull()
        ->and($fresh->start_odometer)->toBe(12345)
        ->and($booking->status)->toBe(BookingStatus::Active); // in-memory model synced
});

// A4 — reference is unique per-tenant, not globally.
it('lets two different tenants hold the same booking reference', function () {
    $reference = 'BK-2026-SAME01';

    // tenant A is already initialized by beforeEach.
    $bookingA = Booking::factory()->create(['reference' => $reference]);

    $tenantB = Tenant::factory()->create();
    tenancy()->end();
    tenancy()->initialize($tenantB);
    $bookingB = Booking::factory()->create(['reference' => $reference]);

    expect($bookingA->reference)->toBe($reference)
        ->and($bookingB->reference)->toBe($reference)
        ->and($bookingB->tenant_id)->toBe($tenantB->id)
        ->and($bookingB->tenant_id)->not->toBe($bookingA->tenant_id);
});

it('still rejects a duplicate booking reference within the same tenant', function () {
    $reference = 'BK-2026-DUP001';
    Booking::factory()->create(['reference' => $reference]);

    expect(fn () => Booking::factory()->create(['reference' => $reference]))
        ->toThrow(QueryException::class);
});
