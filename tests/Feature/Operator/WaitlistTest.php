<?php

use App\Enums\PlanFeature;
use App\Filament\Operator\Resources\Waitlist\Pages\ListWaitlistEntries;
use App\Filament\Operator\Resources\Waitlist\WaitlistEntryResource;
use App\Jobs\SweepWaitlistJob;
use App\Mail\WaitlistSlotOpenMail;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WaitlistEntry;
use App\Services\BookingService;
use App\Services\WaitlistService;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

covers(WaitlistService::class, WaitlistEntry::class);

use function Pest\Laravel\actingAs;

afterEach(fn () => tenancy()->end());

/**
 * @param  array<string, mixed>  $planFeatures
 * @return array{0: Tenant, 1: User}
 */
function waitlistTenant(string $domain, array $planFeatures = [], ?string $planSlug = null): array
{
    if ($planSlug !== null) {
        Plan::factory()->create(['slug' => $planSlug, 'features' => $planFeatures]);
    }

    $tenant = Tenant::factory()->withDomain($domain)->create(['plan' => $planSlug ?? 'ghost-plan']);

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
    Filament::setCurrentPanel(Filament::getPanel('operator'));
    actingAs($owner);

    return [$tenant, $owner];
}

/** A confirmed booking occupying $start..$end on $vehicle. */
function waitlistBooking(Vehicle $vehicle, string $start, string $end): Booking
{
    return Booking::factory()->forVehicle($vehicle)->confirmed()->create([
        'start_date' => $start,
        'end_date' => $end,
    ]);
}

// ── The matching rule ──────────────────────────────────────────────────────────

it('notifies everyone whose dates freed up when they are not competing', function () {
    Mail::fake();
    waitlistTenant('wlboth', [PlanFeature::Waitlist->value => true], 'wlplan');
    $vehicle = Vehicle::factory()->create();

    $booking = waitlistBooking($vehicle, '2030-06-01 10:00', '2030-06-10 10:00');

    // Different windows inside the freed range — they do NOT compete, so both
    // must hear. This is the test a naive "notify only the first" fails.
    $early = WaitlistEntry::factory()->forDates('2030-06-01', '2030-06-03')->create(['vehicle_id' => $vehicle->id]);
    $late = WaitlistEntry::factory()->forDates('2030-06-08', '2030-06-10')->create(['vehicle_id' => $vehicle->id]);

    app(BookingService::class)->cancel($booking);

    Mail::assertQueued(WaitlistSlotOpenMail::class, 2);
    expect($early->fresh()->notified_at)->not->toBeNull()
        ->and($late->fresh()->notified_at)->not->toBeNull();
});

it('tells only the earliest of two people wanting the same dates', function () {
    Mail::fake();
    waitlistTenant('wlcompete', [PlanFeature::Waitlist->value => true], 'wlcompeteplan');
    $vehicle = Vehicle::factory()->create();

    $booking = waitlistBooking($vehicle, '2030-06-01 10:00', '2030-06-10 10:00');

    $first = WaitlistEntry::factory()->forDates('2030-06-02', '2030-06-05')
        ->create(['vehicle_id' => $vehicle->id, 'created_at' => now()->subDay()]);
    $second = WaitlistEntry::factory()->forDates('2030-06-03', '2030-06-06')
        ->create(['vehicle_id' => $vehicle->id, 'created_at' => now()]);

    app(BookingService::class)->cancel($booking);

    // Only one vehicle, overlapping windows — the later joiner stays pending and
    // is offered the slot by the sweep if the first never books.
    Mail::assertQueued(WaitlistSlotOpenMail::class, 1);
    expect($first->fresh()->notified_at)->not->toBeNull()
        ->and($second->fresh()->notified_at)->toBeNull();
});

it('does not notify when another booking still covers the wanted dates', function () {
    Mail::fake();
    waitlistTenant('wlstillbooked', [PlanFeature::Waitlist->value => true], 'wlstillplan');
    $vehicle = Vehicle::factory()->create();

    $cancelled = waitlistBooking($vehicle, '2030-06-01 10:00', '2030-06-05 10:00');
    waitlistBooking($vehicle, '2030-06-02 10:00', '2030-06-04 10:00'); // still there

    $entry = WaitlistEntry::factory()->forDates('2030-06-01', '2030-06-05')->create(['vehicle_id' => $vehicle->id]);

    app(BookingService::class)->cancel($cancelled);

    // Pins the isAvailable() re-check: the cancelled booking was not the only
    // thing in the way. Assert the specific mailable, not assertNothingQueued —
    // cancelling also queues BookingCancelledMail whenever the booking factory
    // gave the customer an email, which would make this flaky.
    Mail::assertNotQueued(WaitlistSlotOpenMail::class);
    expect($entry->fresh()->notified_at)->toBeNull();
});

it('is triggered by a rejected booking too, not only a cancelled one', function () {
    Mail::fake();
    waitlistTenant('wlreject', [PlanFeature::Waitlist->value => true], 'wlrejectplan');
    $vehicle = Vehicle::factory()->create();

    // reject() dispatches BookingRejected, cancel() dispatches BookingCancelled —
    // both land on Cancelled and both free the dates. Handling one loses half.
    $booking = Booking::factory()->forVehicle($vehicle)->create([
        'status' => 'pending',
        'start_date' => '2030-06-01 10:00',
        'end_date' => '2030-06-05 10:00',
    ]);
    $entry = WaitlistEntry::factory()->forDates('2030-06-01', '2030-06-05')->create(['vehicle_id' => $vehicle->id]);

    app(BookingService::class)->reject($booking);

    Mail::assertQueued(WaitlistSlotOpenMail::class, 1);
    expect($entry->fresh()->notified_at)->not->toBeNull();
});

it('is triggered by a moved booking that frees its old vehicle', function () {
    Mail::fake();
    waitlistTenant('wlmoveveh', [PlanFeature::Waitlist->value => true], 'wlmovevehplan');
    $oldVehicle = Vehicle::factory()->create();
    $newVehicle = Vehicle::factory()->create();

    $booking = waitlistBooking($oldVehicle, '2030-06-01 10:00', '2030-06-05 10:00');
    $entry = WaitlistEntry::factory()->forDates('2030-06-01', '2030-06-05')->create(['vehicle_id' => $oldVehicle->id]);

    app(BookingService::class)->move($booking, [
        'vehicle_id' => $newVehicle->id,
        'start_date' => '2030-07-01 10:00',
        'end_date' => '2030-07-05 10:00',
    ]);

    Mail::assertQueued(WaitlistSlotOpenMail::class, 1);
    expect($entry->fresh()->notified_at)->not->toBeNull();
});

it('is triggered by a moved booking that frees its old (non-overlapping) dates on the same vehicle', function () {
    Mail::fake();
    waitlistTenant('wlmovedates', [PlanFeature::Waitlist->value => true], 'wlmovedatesplan');
    $vehicle = Vehicle::factory()->create();

    $booking = waitlistBooking($vehicle, '2030-06-01 10:00', '2030-06-05 10:00');
    $entry = WaitlistEntry::factory()->forDates('2030-06-01', '2030-06-05')->create(['vehicle_id' => $vehicle->id]);

    app(BookingService::class)->move($booking, [
        'vehicle_id' => $vehicle->id,
        'start_date' => '2030-08-01 10:00',
        'end_date' => '2030-08-05 10:00',
    ]);

    Mail::assertQueued(WaitlistSlotOpenMail::class, 1);
    expect($entry->fresh()->notified_at)->not->toBeNull();
});

it('does not notify when a same-vehicle move still overlaps the old dates', function () {
    Mail::fake();
    waitlistTenant('wlmoveoverlap', [PlanFeature::Waitlist->value => true], 'wlmoveoverlapplan');
    $vehicle = Vehicle::factory()->create();

    // Extending the rental on the same car — the old window isn't actually
    // free, the moved booking still covers it. A naive fix would wrongly
    // tell this entry the car is available.
    $booking = waitlistBooking($vehicle, '2030-06-01 10:00', '2030-06-05 10:00');
    $entry = WaitlistEntry::factory()->forDates('2030-06-01', '2030-06-05')->create(['vehicle_id' => $vehicle->id]);

    app(BookingService::class)->move($booking, [
        'vehicle_id' => $vehicle->id,
        'start_date' => '2030-06-01 10:00',
        'end_date' => '2030-06-10 10:00',
    ]);

    Mail::assertNotQueued(WaitlistSlotOpenMail::class);
    expect($entry->fresh()->notified_at)->toBeNull();
});

it('does not notify on a move when the plan disables the waitlist feature', function () {
    Mail::fake();
    waitlistTenant('wlmoveoff', [PlanFeature::Waitlist->value => false], 'wlmoveoffplan');
    $oldVehicle = Vehicle::factory()->create();
    $newVehicle = Vehicle::factory()->create();

    $booking = waitlistBooking($oldVehicle, '2030-06-01 10:00', '2030-06-05 10:00');

    app(BookingService::class)->move($booking, [
        'vehicle_id' => $newVehicle->id,
        'start_date' => '2030-07-01 10:00',
        'end_date' => '2030-07-05 10:00',
    ]);

    Mail::assertNotQueued(WaitlistSlotOpenMail::class);
});

it('never mails the same entry twice', function () {
    Mail::fake();
    [$tenant] = waitlistTenant('wlonce', [PlanFeature::Waitlist->value => true], 'wlonceplan');
    $vehicle = Vehicle::factory()->create();

    $booking = waitlistBooking($vehicle, '2030-06-01 10:00', '2030-06-10 10:00');
    WaitlistEntry::factory()->forDates('2030-06-02', '2030-06-04')->create(['vehicle_id' => $vehicle->id]);

    app(BookingService::class)->cancel($booking);
    Mail::assertQueued(WaitlistSlotOpenMail::class, 1);

    Mail::fake();
    (new SweepWaitlistJob($tenant))->handle(app(WaitlistService::class));
    Mail::assertNothingQueued();
});

it('offers the dates to the next in line on the sweep when the first never booked', function () {
    Mail::fake();
    [$tenant] = waitlistTenant('wlnext', [PlanFeature::Waitlist->value => true], 'wlnextplan');
    $vehicle = Vehicle::factory()->create();

    // First was already told and did nothing; the vehicle is still free.
    WaitlistEntry::factory()->forDates('2030-06-02', '2030-06-05')->notified()
        ->create(['vehicle_id' => $vehicle->id, 'created_at' => now()->subDay()]);
    $next = WaitlistEntry::factory()->forDates('2030-06-03', '2030-06-06')
        ->create(['vehicle_id' => $vehicle->id, 'created_at' => now()]);

    (new SweepWaitlistJob($tenant))->handle(app(WaitlistService::class));

    Mail::assertQueued(WaitlistSlotOpenMail::class, 1);
    expect($next->fresh()->notified_at)->not->toBeNull();
});

it('never mails an entry whose dates have already passed, and sweeps it away', function () {
    Mail::fake();
    [$tenant] = waitlistTenant('wlexpired', [PlanFeature::Waitlist->value => true], 'wlexpiredplan');
    $vehicle = Vehicle::factory()->create();

    WaitlistEntry::factory()->expired()->create(['vehicle_id' => $vehicle->id]);

    (new SweepWaitlistJob($tenant))->handle(app(WaitlistService::class));

    Mail::assertNothingQueued();
    expect(WaitlistEntry::query()->count())->toBe(0);
});

it('never touches another tenant\'s entries', function () {
    Mail::fake();
    waitlistTenant('wlmine', [PlanFeature::Waitlist->value => true], 'wlmineplan');
    $mine = Vehicle::factory()->create();
    $booking = waitlistBooking($mine, '2030-06-01 10:00', '2030-06-10 10:00');
    tenancy()->end();

    $other = Tenant::factory()->withDomain('wlother')->create();
    tenancy()->initialize($other);
    $theirVehicle = Vehicle::factory()->create();
    $theirs = WaitlistEntry::factory()->forDates('2030-06-02', '2030-06-04')->create(['vehicle_id' => $theirVehicle->id]);
    tenancy()->end();

    $mineTenant = Tenant::query()->whereRelation('domains', 'domain', tenant_domain('wlmine'))->firstOrFail();
    tenancy()->initialize($mineTenant);
    app(BookingService::class)->cancel($booking);

    expect($theirs->fresh()->notified_at)->toBeNull();
});

// ── Gating ─────────────────────────────────────────────────────────────────────

it('shows the waitlist panel when the plan enables it', function () {
    waitlistTenant('wlpanelon', [PlanFeature::Waitlist->value => true], 'wlpanelonplan');
    $vehicle = Vehicle::factory()->create(['is_public' => true]);

    Livewire::test('pages::public.vehicle-show', ['vehicle' => $vehicle])
        ->assertOk()
        ->assertSee(__('booking.waitlist_heading'));
});

it('hides the waitlist panel when the plan disables it', function () {
    waitlistTenant('wlpaneloff', [PlanFeature::Waitlist->value => false], 'wlpaneloffplan');
    $vehicle = Vehicle::factory()->create(['is_public' => true]);

    Livewire::test('pages::public.vehicle-show', ['vehicle' => $vehicle])
        ->assertOk()
        ->assertDontSee(__('booking.waitlist_heading'));
});

it('refuses a direct joinWaitlist call when the plan disables it', function () {
    waitlistTenant('wljoinoff', [PlanFeature::Waitlist->value => false], 'wljoinoffplan');
    $vehicle = Vehicle::factory()->create(['is_public' => true]);

    // The real gate. A public Livewire SFC has no framework authorization hook —
    // unlike a Filament page, this method is callable over the wire whatever the
    // page rendered, so hiding the panel proves nothing.
    Livewire::test('pages::public.vehicle-show', ['vehicle' => $vehicle])
        ->set('waitlistStart', now()->addDays(5)->toDateString())
        ->set('waitlistEnd', now()->addDays(8)->toDateString())
        ->set('waitlistName', 'Sneaky')
        ->set('waitlistEmail', 'sneaky@example.com')
        ->call('joinWaitlist')
        ->assertNotFound();

    expect(WaitlistEntry::query()->count())->toBe(0);
});

it('skips a gated-off tenant in the sweep command and dispatches for a gated-on one', function () {
    Queue::fake();

    // The stock alert (#3) shares this command and is gated separately, so it has
    // to be off too for "gated off" to mean no sweep at all.
    waitlistTenant('wlcmdoff', [
        PlanFeature::Waitlist->value => false,
        PlanFeature::StockAlert->value => false,
    ], 'wlcmdoffplan');
    tenancy()->end();

    test()->artisan('waitlist:sweep')->assertSuccessful();
    Queue::assertNotPushed(SweepWaitlistJob::class);

    waitlistTenant('wlcmdon', [PlanFeature::Waitlist->value => true], 'wlcmdonplan');
    tenancy()->end();

    test()->artisan('waitlist:sweep')->assertSuccessful();
    Queue::assertPushed(SweepWaitlistJob::class, 1);
});

it('hides the operator resource from staff', function () {
    [$tenant] = waitlistTenant('wlresstaff', [PlanFeature::Waitlist->value => true], 'wlresstaffplan');

    expect(WaitlistEntryResource::canAccess())->toBeTrue();

    actingAs(User::factory()->staff()->create(['tenant_id' => $tenant->id]));
    expect(WaitlistEntryResource::canAccess())->toBeFalse();
});

it('hides the operator resource from an owner whose plan disables it', function () {
    // Stock-alert entries live in this same resource and are gated separately, so
    // both have to be off before the list has nothing to show.
    waitlistTenant('wlresoff', [
        PlanFeature::Waitlist->value => false,
        PlanFeature::StockAlert->value => false,
    ], 'wlresoffplan');

    expect(WaitlistEntryResource::canAccess())->toBeFalse();
});

// ── Joining ────────────────────────────────────────────────────────────────────

it('records a join from the public panel without creating a customer', function () {
    waitlistTenant('wljoin', [PlanFeature::Waitlist->value => true], 'wljoinplan');
    $vehicle = Vehicle::factory()->create(['is_public' => true]);

    Livewire::test('pages::public.vehicle-show', ['vehicle' => $vehicle])
        ->set('waitlistStart', now()->addDays(5)->toDateString())
        ->set('waitlistEnd', now()->addDays(8)->toDateString())
        ->set('waitlistName', 'Ana')
        ->set('waitlistEmail', 'ana@example.com')
        ->set('waitlistPhone', '049111222')
        ->call('joinWaitlist')
        ->assertSet('waitlistJoined', true);

    $entry = WaitlistEntry::query()->sole();

    expect($entry->email)->toBe('ana@example.com')
        ->and($entry->vehicle_id)->toBe($vehicle->id)
        ->and($entry->notified_at)->toBeNull()
        // Deliberately no CRM write: an unauthenticated form must not create a
        // Customer, and customers are keyed by phone anyway.
        ->and(Customer::query()->count())->toBe(0);
});

it('shows the same thank-you when someone joins twice, without leaking that they are on the list', function () {
    waitlistTenant('wldupe', [PlanFeature::Waitlist->value => true], 'wldupeplan');
    $vehicle = Vehicle::factory()->create(['is_public' => true]);

    $join = fn () => Livewire::test('pages::public.vehicle-show', ['vehicle' => $vehicle])
        ->set('waitlistStart', now()->addDays(5)->toDateString())
        ->set('waitlistEnd', now()->addDays(8)->toDateString())
        ->set('waitlistName', 'Ana')
        ->set('waitlistEmail', 'dupe@example.com')
        ->call('joinWaitlist')
        ->assertSet('waitlistJoined', true);

    $join();
    $join();

    expect(WaitlistEntry::query()->count())->toBe(1);
});

it('throttles repeated joins from the same visitor', function () {
    RateLimiter::clear('waitlist-join:1:127.0.0.1');
    waitlistTenant('wlthrottle', [PlanFeature::Waitlist->value => true], 'wlthrottleplan');
    $vehicle = Vehicle::factory()->create(['is_public' => true]);

    // Nothing else in this app throttles a public action, and this one causes
    // mail and accepts an email address.
    foreach (range(1, 5) as $i) {
        Livewire::test('pages::public.vehicle-show', ['vehicle' => $vehicle])
            ->set('waitlistStart', now()->addDays(5)->toDateString())
            ->set('waitlistEnd', now()->addDays(8)->toDateString())
            ->set('waitlistName', 'Ana')
            ->set('waitlistEmail', "spam{$i}@example.com")
            ->call('joinWaitlist')
            ->assertSet('waitlistJoined', true);
    }

    Livewire::test('pages::public.vehicle-show', ['vehicle' => $vehicle])
        ->set('waitlistStart', now()->addDays(5)->toDateString())
        ->set('waitlistEnd', now()->addDays(8)->toDateString())
        ->set('waitlistName', 'Ana')
        ->set('waitlistEmail', 'spam6@example.com')
        ->call('joinWaitlist')
        ->assertSet('waitlistJoined', false)
        ->assertSet('waitlistError', __('booking.waitlist_throttled'));

    expect(WaitlistEntry::query()->count())->toBe(5);
});

it('rejects a past or inverted date range', function () {
    waitlistTenant('wlbaddates', [PlanFeature::Waitlist->value => true], 'wlbaddatesplan');
    $vehicle = Vehicle::factory()->create(['is_public' => true]);

    Livewire::test('pages::public.vehicle-show', ['vehicle' => $vehicle])
        ->set('waitlistStart', now()->addDays(8)->toDateString())
        ->set('waitlistEnd', now()->addDays(5)->toDateString())
        ->set('waitlistName', 'Ana')
        ->set('waitlistEmail', 'bad@example.com')
        ->call('joinWaitlist')
        ->assertHasErrors('waitlistEnd');

    expect(WaitlistEntry::query()->count())->toBe(0);
});

it('lists entries for the owner in join order', function () {
    waitlistTenant('wllist', [PlanFeature::Waitlist->value => true], 'wllistplan');
    $vehicle = Vehicle::factory()->create();

    WaitlistEntry::factory()->create(['vehicle_id' => $vehicle->id, 'email' => 'first@example.com']);

    Livewire::test(ListWaitlistEntries::class)
        ->assertOk()
        ->assertSee('first@example.com');
});

it('rejects an inverted range at the service, not just in the form', function () {
    // The Livewire form validates first, so the service guard needs its own test.
    waitlistTenant('wlsvcinv', [PlanFeature::Waitlist->value => true], 'wlsvcinvplan');
    $vehicle = Vehicle::factory()->create();

    expect(fn () => app(WaitlistService::class)->join($vehicle, [
        'name' => 'Ana',
        'email' => 'ana@example.com',
        'start_date' => now()->addDays(8)->toDateString(),
        'end_date' => now()->addDays(5)->toDateString(),
    ]))->toThrow(InvalidArgumentException::class);

    expect(WaitlistEntry::query()->count())->toBe(0);
});

it('rejects a start date in the past at the service', function () {
    waitlistTenant('wlsvcpast', [PlanFeature::Waitlist->value => true], 'wlsvcpastplan');
    $vehicle = Vehicle::factory()->create();

    expect(fn () => app(WaitlistService::class)->join($vehicle, [
        'name' => 'Ana',
        'email' => 'ana@example.com',
        'start_date' => now()->subDay()->toDateString(),
        'end_date' => now()->addDays(5)->toDateString(),
    ]))->toThrow(InvalidArgumentException::class);

    expect(WaitlistEntry::query()->count())->toBe(0);
});

it('accepts a range starting today', function () {
    // today() is the earliest joinable start — the boundary of the past-date guard.
    waitlistTenant('wlsvctoday', [PlanFeature::Waitlist->value => true], 'wlsvctodayplan');
    $vehicle = Vehicle::factory()->create();

    $entry = app(WaitlistService::class)->join($vehicle, [
        'name' => 'Ana',
        'email' => 'ana@example.com',
        'start_date' => today()->toDateString(),
        'end_date' => today()->addDays(3)->toDateString(),
    ]);

    expect($entry->exists)->toBeTrue();
});

it('skips a blocked entry but still tells a later non-overlapping one', function () {
    // A skipped entry must not abandon the rest of the queue.
    Mail::fake();
    [$tenant] = waitlistTenant('wlskip', [PlanFeature::Waitlist->value => true], 'wlskipplan');
    $vehicle = Vehicle::factory()->create();

    // June 2-5 stays unbookable: a confirmed booking still covers it.
    Booking::factory()->forVehicle($vehicle)->confirmed()->create([
        'start_date' => '2030-06-02',
        'end_date' => '2030-06-05',
    ]);

    WaitlistEntry::factory()->forDates('2030-06-02', '2030-06-05')
        ->create(['vehicle_id' => $vehicle->id, 'created_at' => now()->subDay()]);
    WaitlistEntry::factory()->forDates('2030-07-10', '2030-07-14')
        ->create(['vehicle_id' => $vehicle->id, 'created_at' => now()]);

    $notified = app(WaitlistService::class)->notifyMatching($vehicle);

    expect($notified)->toBe(1);
    Mail::assertQueued(WaitlistSlotOpenMail::class, 1);
});

it('skips an entry whose dates a earlier entry already claimed but keeps going', function () {
    // Losing the claim must not abandon entries further down the queue.
    Mail::fake();
    [$tenant] = waitlistTenant('wlclaim', [PlanFeature::Waitlist->value => true], 'wlclaimplan');
    $vehicle = Vehicle::factory()->create();

    WaitlistEntry::factory()->forDates('2030-06-02', '2030-06-05')
        ->create(['vehicle_id' => $vehicle->id, 'created_at' => now()->subDays(2)]);
    // Overlaps the first, so it loses the claim and is skipped.
    WaitlistEntry::factory()->forDates('2030-06-03', '2030-06-06')
        ->create(['vehicle_id' => $vehicle->id, 'created_at' => now()->subDay()]);
    // Independent dates — must still be told.
    WaitlistEntry::factory()->forDates('2030-07-10', '2030-07-14')
        ->create(['vehicle_id' => $vehicle->id, 'created_at' => now()]);

    $notified = app(WaitlistService::class)->notifyMatching($vehicle);

    expect($notified)->toBe(2);
    Mail::assertQueued(WaitlistSlotOpenMail::class, 2);
});

it('treats a dateless entry as overlapping any range', function () {
    waitlistTenant('wloverlap', [PlanFeature::Waitlist->value => true], 'wloverlapplan');
    $vehicle = Vehicle::factory()->create();

    $dateless = WaitlistEntry::factory()->stockAlert()->create(['vehicle_id' => $vehicle->id]);
    $dated = WaitlistEntry::factory()->forDates('2030-06-02', '2030-06-05')
        ->create(['vehicle_id' => $vehicle->id]);

    expect($dateless->overlaps(Carbon::parse('2030-01-01'), Carbon::parse('2030-01-05')))->toBeTrue()
        // A missing comparison range means "no narrowing", so everything matches.
        ->and($dated->overlaps(null, null))->toBeTrue()
        ->and($dated->overlaps(Carbon::parse('2030-06-04'), Carbon::parse('2030-06-08')))->toBeTrue()
        ->and($dated->overlaps(Carbon::parse('2030-08-01'), Carbon::parse('2030-08-05')))->toBeFalse();
});

it('keeps the English and Albanian booking translations in sync', function () {
    $en = require lang_path('en/booking.php');
    $sq = require lang_path('sq/booking.php');

    expect(array_keys($sq))->toBe(array_keys($en));
});
