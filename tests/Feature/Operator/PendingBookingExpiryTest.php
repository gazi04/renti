<?php

use App\Enums\BookingStatus;
use App\Events\BookingCancelled;
use App\Jobs\ExpireStalePendingBookingsJob;
use App\Mail\BookingCancelledMail;
use App\Models\Booking;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\AvailabilityService;
use App\Services\BookingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

afterEach(fn () => tenancy()->end());

/**
 * A tenant with one operator, tenancy initialized. No Filament panel: this
 * suite exercises a queued sweep, not a page.
 *
 * @return array{0: Tenant, 1: User}
 */
function expiryTenant(string $domain): array
{
    $tenant = Tenant::factory()->withDomain($domain)->create();

    $owner = new User;
    $owner->forceFill([
        'tenant_id' => $tenant->id,
        'role' => 'operator',
        'name' => 'Owner',
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
    ])->save();

    tenancy()->initialize($tenant);

    return [$tenant, $owner];
}

/** A pending booking on $vehicle, created $hoursAgo hours ago, for far-future dates. */
function pendingBooking(Vehicle $vehicle, int $hoursAgo = 0, array $attributes = []): Booking
{
    return Booking::factory()->forVehicle($vehicle)->create([
        'start_date' => '2031-06-01 10:00:00',
        'end_date' => '2031-06-05 10:00:00',
        'created_at' => now()->subHours($hoursAgo),
        ...$attributes,
    ]);
}

it('cancels a pending booking older than the window and frees its vehicle', function () {
    [$tenant] = expiryTenant('expstale');
    $vehicle = Vehicle::factory()->create();
    $booking = pendingBooking($vehicle, hoursAgo: 49);

    expect(app(AvailabilityService::class)->isAvailable(
        $vehicle, Carbon::parse($booking->start_date), Carbon::parse($booking->end_date)
    ))->toBeFalse();

    (new ExpireStalePendingBookingsJob($tenant))->handle(app(BookingService::class));

    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::Cancelled)
        ->and($booking->cancellation_reason)->toBe(__('emails.booking_cancelled.expired_reason', [], 'sq'))
        ->and(app(AvailabilityService::class)->isAvailable(
            $vehicle, Carbon::parse($booking->start_date), Carbon::parse($booking->end_date)
        ))->toBeTrue();
});

it('leaves a pending booking inside the window alone', function () {
    [$tenant] = expiryTenant('expfresh');
    $booking = pendingBooking(Vehicle::factory()->create(), hoursAgo: 47);

    (new ExpireStalePendingBookingsJob($tenant))->handle(app(BookingService::class));

    expect($booking->refresh()->status)->toBe(BookingStatus::Pending);
});

it('cancels a fresh pending booking whose pickup day has already ended', function () {
    [$tenant] = expiryTenant('exppast');
    $booking = pendingBooking(Vehicle::factory()->create(), hoursAgo: 1, attributes: [
        'start_date' => now()->subDay(),
        'end_date' => now()->addDays(2),
    ]);

    (new ExpireStalePendingBookingsJob($tenant))->handle(app(BookingService::class));

    expect($booking->refresh()->status)->toBe(BookingStatus::Cancelled);
});

/**
 * A pending booking made the way the storefront makes it: date-only, so
 * start_date lands at 00:00 of the pickup day.
 */
function storefrontBooking(Vehicle $vehicle, string $startDate, string $endDate): Booking
{
    return app(BookingService::class)->create([
        'vehicle_id' => $vehicle->id,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'customer_name' => 'Ana Krasniqi',
        'customer_phone' => '+38344111222',
    ]);
}

it('keeps a same-day storefront booking pending for the rest of its pickup day', function () {
    // The pickup day is the deadline, not its first second: a booking made at
    // 10:00 for today used to be cancelled by the next hourly run.
    [$tenant] = expiryTenant('expsameday');
    $this->travelTo('2026-10-06 10:00:00');
    $booking = storefrontBooking(Vehicle::factory()->create(), '2026-10-06', '2026-10-08');
    $this->travelTo('2026-10-06 23:00:00');

    (new ExpireStalePendingBookingsJob($tenant))->handle(app(BookingService::class));

    expect($booking->refresh()->status)->toBe(BookingStatus::Pending);
});

it('keeps an evening booking for tomorrow pending overnight, until the operator can answer', function () {
    [$tenant] = expiryTenant('expovernight');
    $this->travelTo('2026-10-06 20:00:00');
    $booking = storefrontBooking(Vehicle::factory()->create(), '2026-10-07', '2026-10-09');
    $this->travelTo('2026-10-07 08:00:00');

    (new ExpireStalePendingBookingsJob($tenant))->handle(app(BookingService::class));

    expect($booking->refresh()->status)->toBe(BookingStatus::Pending);
});

it('cancels an unanswered storefront booking once its pickup day is over', function () {
    [$tenant] = expiryTenant('expdayover');
    $this->travelTo('2026-10-06 09:00:00');
    $booking = storefrontBooking(Vehicle::factory()->create(), '2026-10-06', '2026-10-08');
    $this->travelTo('2026-10-07 00:30:00');

    (new ExpireStalePendingBookingsJob($tenant))->handle(app(BookingService::class));

    expect($booking->refresh()->status)->toBe(BookingStatus::Cancelled);
});

it('never touches a booking the operator already acted on', function () {
    [$tenant] = expiryTenant('expacted');
    $vehicle = Vehicle::factory()->create();

    $confirmed = pendingBooking($vehicle, hoursAgo: 100, attributes: ['status' => BookingStatus::Confirmed]);
    $active = pendingBooking($vehicle, hoursAgo: 100, attributes: ['status' => BookingStatus::Active]);
    $completed = pendingBooking($vehicle, hoursAgo: 100, attributes: ['status' => BookingStatus::Completed]);

    (new ExpireStalePendingBookingsJob($tenant))->handle(app(BookingService::class));

    expect($confirmed->refresh()->status)->toBe(BookingStatus::Confirmed)
        ->and($active->refresh()->status)->toBe(BookingStatus::Active)
        ->and($completed->refresh()->status)->toBe(BookingStatus::Completed);
});

it('emails the customer and bells the operator, without emailing the operator', function () {
    Mail::fake();

    [$tenant, $owner] = expiryTenant('expnotify');
    pendingBooking(Vehicle::factory()->create(), hoursAgo: 72, attributes: [
        'customer_email' => 'renter@example.com',
    ]);

    // The queue runs sync under test, so the BookingCancelled listener has
    // already fired by the time handle() returns.
    (new ExpireStalePendingBookingsJob($tenant))->handle(app(BookingService::class));

    Mail::assertQueued(BookingCancelledMail::class, 1);
    Mail::assertQueued(BookingCancelledMail::class, fn (BookingCancelledMail $mail): bool => $mail->hasTo('renter@example.com'));

    expect($owner->notifications()->count())->toBe(1)
        ->and($owner->notifications()->first()->data['title'])->toBe(__('panel.booking_expired_bell_title'));
});

it('is a no-op on a booking that is no longer pending, dispatching no second event', function () {
    Event::fake([BookingCancelled::class]);

    [$tenant] = expiryTenant('exprace');
    $booking = pendingBooking(Vehicle::factory()->create(), hoursAgo: 72);
    $bookings = app(BookingService::class);

    expect($bookings->expire($booking, 'first'))->toBeTrue()
        ->and($bookings->expire($booking, 'second'))->toBeFalse()
        ->and($booking->refresh()->cancellation_reason)->toBe('first');

    Event::assertDispatchedTimes(BookingCancelled::class, 1);
});

it('does not reach across tenants', function () {
    [$tenantA] = expiryTenant('expcrossa');
    $ownBooking = pendingBooking(Vehicle::factory()->create(), hoursAgo: 72);
    tenancy()->end();

    [$tenantB] = expiryTenant('expcrossb');
    $otherBooking = pendingBooking(Vehicle::factory()->create(), hoursAgo: 72);
    tenancy()->end();

    (new ExpireStalePendingBookingsJob($tenantA))->handle(app(BookingService::class));

    tenancy()->initialize($tenantB);
    expect($otherBooking->refresh()->status)->toBe(BookingStatus::Pending);

    tenancy()->end();
    tenancy()->initialize($tenantA);
    expect($ownBooking->refresh()->status)->toBe(BookingStatus::Cancelled);
});

it('queues one sweep per active tenant, on every plan, and none for a suspended one', function () {
    Queue::fake();

    expiryTenant('expcmdone');
    tenancy()->end();
    expiryTenant('expcmdtwo');
    tenancy()->end();
    Tenant::factory()->withDomain('expcmdsuspended')->suspended()->create();

    test()->artisan('bookings:expire-pending')->assertSuccessful();

    Queue::assertPushed(ExpireStalePendingBookingsJob::class, 2);
});

it('configures retries and timeout for expiry sweep failures', function () {
    $job = new ExpireStalePendingBookingsJob(Tenant::factory()->make());

    expect($job->tries)->toBe(3)
        ->and($job->timeout)->toBe(60)
        ->and($job->backoff())->toBe([60, 300, 900]);
});

it('logs tenant context when the expiry sweep fails permanently', function () {
    $tenant = Tenant::factory()->create();

    Log::spy();

    (new ExpireStalePendingBookingsJob($tenant))->failed(new Exception('boom'));

    Log::shouldHaveReceived('error')->once()->withArgs(
        fn (string $message, array $context): bool => $message === 'Pending booking expiry sweep failed'
            && $context['tenant_id'] === $tenant->id
    );
});
