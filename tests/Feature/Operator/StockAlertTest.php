<?php

use App\Enums\PlanFeature;
use App\Enums\VehicleStatus;
use App\Filament\Operator\Resources\Waitlist\WaitlistEntryResource;
use App\Jobs\SweepWaitlistJob;
use App\Mail\VehicleBackInStockMail;
use App\Mail\WaitlistSlotOpenMail;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WaitlistEntry;
use App\Services\WaitlistService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

covers(WaitlistService::class);

afterEach(fn () => tenancy()->end());

/**
 * @param  array<string, mixed>  $planFeatures
 * @return array{0: Tenant, 1: User}
 */
function stockAlertTenant(string $domain, array $planFeatures = [], ?string $planSlug = null): array
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

/** A public vehicle that is off the road — the only state that hosts the panel. */
function unavailableVehicle(): Vehicle
{
    return Vehicle::factory()->create([
        'is_public' => true,
        'status' => VehicleStatus::UnderMaintenance,
    ]);
}

// ── The notify rule ────────────────────────────────────────────────────────────

it('mails everyone waiting when the vehicle comes back, not just the first', function () {
    Mail::fake();
    stockAlertTenant('sabase', [PlanFeature::StockAlert->value => true], 'saplan');
    $vehicle = unavailableVehicle();

    // Every dateless entry "overlaps" every other, so a FIFO claim loop would mail
    // exactly one of these. A returning vehicle is free for all dates — nobody is
    // competing, so everybody hears.
    $first = WaitlistEntry::factory()->stockAlert()->create(['vehicle_id' => $vehicle->id, 'created_at' => now()->subDay()]);
    $second = WaitlistEntry::factory()->stockAlert()->create(['vehicle_id' => $vehicle->id, 'created_at' => now()]);

    $vehicle->update(['status' => VehicleStatus::Available]);

    Mail::assertQueued(VehicleBackInStockMail::class, 2);
    expect($first->fresh()->notified_at)->not->toBeNull()
        ->and($second->fresh()->notified_at)->not->toBeNull();
});

it('fires on a plain status update, not only through the operator form', function () {
    Mail::fake();
    stockAlertTenant('samodel', [PlanFeature::StockAlert->value => true], 'samodelplan');
    $vehicle = unavailableVehicle();
    WaitlistEntry::factory()->stockAlert()->create(['vehicle_id' => $vehicle->id]);

    // The trigger is a model hook precisely so no write path can opt out of it.
    $vehicle->update(['status' => VehicleStatus::Available]);

    Mail::assertQueued(VehicleBackInStockMail::class, 1);
});

it('mails when a hidden vehicle is published again', function () {
    Mail::fake();
    stockAlertTenant('sarepublish', [PlanFeature::StockAlert->value => true], 'sarepublishplan');
    $vehicle = Vehicle::factory()->create(['is_public' => false, 'status' => VehicleStatus::Available]);
    WaitlistEntry::factory()->stockAlert()->create(['vehicle_id' => $vehicle->id]);

    // Someone can join while the vehicle is on the road, then have it hidden from
    // under them — is_public coming back is as much a republish as status is.
    $vehicle->update(['is_public' => true]);

    Mail::assertQueued(VehicleBackInStockMail::class, 1);
});

it('never mails for a vehicle that is still hidden', function () {
    Mail::fake();
    stockAlertTenant('sahidden', [PlanFeature::StockAlert->value => true], 'sahiddenplan');
    $vehicle = Vehicle::factory()->create(['is_public' => false, 'status' => VehicleStatus::UnderMaintenance]);
    $entry = WaitlistEntry::factory()->stockAlert()->create(['vehicle_id' => $vehicle->id]);

    // Bookable, but hidden — hiding is deliberate and must stay absolute.
    $vehicle->update(['status' => VehicleStatus::Available]);

    Mail::assertNothingQueued();
    expect($entry->fresh()->notified_at)->toBeNull();
});

it('never mails the same entry twice', function () {
    Mail::fake();
    stockAlertTenant('saonce', [PlanFeature::StockAlert->value => true], 'saonceplan');
    $vehicle = unavailableVehicle();
    WaitlistEntry::factory()->stockAlert()->create(['vehicle_id' => $vehicle->id]);

    $vehicle->update(['status' => VehicleStatus::Available]);
    $vehicle->update(['status' => VehicleStatus::UnderMaintenance]);
    $vehicle->update(['status' => VehicleStatus::Available]);

    // notified_at is the idempotency key; a vehicle can bounce in and out of the
    // fleet without re-mailing people who were already told.
    Mail::assertQueued(VehicleBackInStockMail::class, 1);
});

it('leaves date-range waitlist entries alone', function () {
    Mail::fake();
    stockAlertTenant('samixed', [
        PlanFeature::StockAlert->value => true,
        PlanFeature::Waitlist->value => true,
    ], 'samixedplan');
    $vehicle = unavailableVehicle();

    $dated = WaitlistEntry::factory()->forDates('2030-06-01', '2030-06-05')->create(['vehicle_id' => $vehicle->id]);
    $dateless = WaitlistEntry::factory()->stockAlert()->create(['vehicle_id' => $vehicle->id]);

    $vehicle->update(['status' => VehicleStatus::Available]);

    // The two triggers share a table and must not read each other's rows.
    Mail::assertQueued(VehicleBackInStockMail::class, 1);
    Mail::assertNotQueued(WaitlistSlotOpenMail::class);
    expect($dated->fresh()->notified_at)->toBeNull()
        ->and($dateless->fresh()->notified_at)->not->toBeNull();
});

it('never touches another tenant\'s entries', function () {
    Mail::fake();

    [$other] = stockAlertTenant('saother', [PlanFeature::StockAlert->value => true], 'saotherplan');
    $theirVehicle = unavailableVehicle();
    $theirs = WaitlistEntry::factory()->stockAlert()->create(['vehicle_id' => $theirVehicle->id]);
    tenancy()->end();

    stockAlertTenant('samine', [PlanFeature::StockAlert->value => true], 'samineplan');
    $myVehicle = unavailableVehicle();
    WaitlistEntry::factory()->stockAlert()->create(['vehicle_id' => $myVehicle->id]);

    $myVehicle->update(['status' => VehicleStatus::Available]);

    Mail::assertQueued(VehicleBackInStockMail::class, 1);

    tenancy()->initialize($other);
    expect($theirs->fresh()->notified_at)->toBeNull();
});

// ── Gating ─────────────────────────────────────────────────────────────────────

it('does not mail when the plan disables the feature', function () {
    Mail::fake();
    stockAlertTenant('sagateoff', [PlanFeature::StockAlert->value => false], 'sagateoffplan');
    $vehicle = unavailableVehicle();
    $entry = WaitlistEntry::factory()->stockAlert()->create(['vehicle_id' => $vehicle->id]);

    $vehicle->update(['status' => VehicleStatus::Available]);

    Mail::assertNothingQueued();
    expect($entry->fresh()->notified_at)->toBeNull();
});

it('shows the stock alert panel when the plan enables it', function () {
    stockAlertTenant('sapanelon', [PlanFeature::StockAlert->value => true], 'sapanelonplan');
    $vehicle = unavailableVehicle();

    Livewire::test('pages::public.vehicle-show', ['vehicle' => $vehicle])
        ->assertOk()
        ->assertSee(__('booking.stock_alert_heading'));
});

it('hides the stock alert panel when the plan disables it', function () {
    stockAlertTenant('sapaneloff', [PlanFeature::StockAlert->value => false], 'sapaneloffplan');
    $vehicle = unavailableVehicle();

    Livewire::test('pages::public.vehicle-show', ['vehicle' => $vehicle])
        ->assertOk()
        ->assertDontSee(__('booking.stock_alert_heading'));
});

it('hides the stock alert panel while the vehicle is still bookable', function () {
    stockAlertTenant('sapanelbookable', [PlanFeature::StockAlert->value => true], 'sapanelbookableplan');
    $vehicle = Vehicle::factory()->create(['is_public' => true, 'status' => VehicleStatus::Available]);

    // Nothing to wait for — the booking form is right there.
    Livewire::test('pages::public.vehicle-show', ['vehicle' => $vehicle])
        ->assertOk()
        ->assertDontSee(__('booking.stock_alert_heading'));
});

it('refuses a direct joinStockAlert call when the plan disables it', function () {
    stockAlertTenant('sajoinoff', [PlanFeature::StockAlert->value => false], 'sajoinoffplan');
    $vehicle = unavailableVehicle();

    // The real gate. A public Livewire SFC has no framework authorization hook —
    // unlike a Filament page, this method is callable over the wire whatever the
    // page rendered, so hiding the panel proves nothing.
    Livewire::test('pages::public.vehicle-show', ['vehicle' => $vehicle])
        ->set('stockAlertName', 'Sneaky')
        ->set('stockAlertEmail', 'sneaky@example.com')
        ->call('joinStockAlert')
        ->assertNotFound();

    expect(WaitlistEntry::query()->count())->toBe(0);
});

it('refuses a direct joinStockAlert call for a bookable vehicle', function () {
    stockAlertTenant('sajoinbookable', [PlanFeature::StockAlert->value => true], 'sajoinbookableplan');
    $vehicle = Vehicle::factory()->create(['is_public' => true, 'status' => VehicleStatus::Available]);

    Livewire::test('pages::public.vehicle-show', ['vehicle' => $vehicle])
        ->set('stockAlertName', 'Sneaky')
        ->set('stockAlertEmail', 'sneaky@example.com')
        ->call('joinStockAlert')
        ->assertNotFound();

    expect(WaitlistEntry::query()->count())->toBe(0);
});

it('dispatches the sweep for a tenant with only the stock alert enabled', function () {
    Queue::fake();

    stockAlertTenant('sacmdoff', [
        PlanFeature::StockAlert->value => false,
        PlanFeature::Waitlist->value => false,
    ], 'sacmdoffplan');
    tenancy()->end();

    test()->artisan('waitlist:sweep')->assertSuccessful();
    Queue::assertNotPushed(SweepWaitlistJob::class);

    // The command fans out on either feature; the job picks its passes.
    stockAlertTenant('sacmdon', [
        PlanFeature::StockAlert->value => true,
        PlanFeature::Waitlist->value => false,
    ], 'sacmdonplan');
    tenancy()->end();

    test()->artisan('waitlist:sweep')->assertSuccessful();
    Queue::assertPushed(SweepWaitlistJob::class, 1);
});

it('shows the operator resource to an owner who has the stock alert but not the waitlist', function () {
    stockAlertTenant('saresonly', [
        PlanFeature::StockAlert->value => true,
        PlanFeature::Waitlist->value => false,
    ], 'saresonlyplan');

    // Both entry types live in one resource — gating it on Waitlist alone would
    // leave this operator collecting entries they could never read.
    expect(WaitlistEntryResource::canAccess())->toBeTrue();
});

it('hides the operator resource from an owner who has neither feature', function () {
    stockAlertTenant('saresneither', [
        PlanFeature::StockAlert->value => false,
        PlanFeature::Waitlist->value => false,
    ], 'saresneitherplan');

    expect(WaitlistEntryResource::canAccess())->toBeFalse();
});

// ── The entry point ────────────────────────────────────────────────────────────

it('renders the page for an unavailable vehicle instead of 404ing', function () {
    stockAlertTenant('sashow', [PlanFeature::StockAlert->value => true], 'sashowplan');
    $vehicle = unavailableVehicle();

    // The whole feature depends on this page existing — before #3 it 404'd, so
    // there was nowhere to host the panel. The booking card shows the panel
    // itself (not the plain notice, which only appears when the feature is
    // off — see VehicleShowTest) since the plan allows it here.
    Livewire::test('pages::public.vehicle-show', ['vehicle' => $vehicle])
        ->assertOk()
        ->assertSee(__('booking.vehicle_unavailable_badge'))
        ->assertDontSee(__('booking.book_now'));
});

it('still 404s the page for a hidden vehicle', function () {
    stockAlertTenant('sashowhidden', [PlanFeature::StockAlert->value => true], 'sashowhiddenplan');
    $vehicle = Vehicle::factory()->create(['is_public' => false, 'status' => VehicleStatus::Available]);

    Livewire::test('pages::public.vehicle-show', ['vehicle' => $vehicle])
        ->assertNotFound();
});

it('still 404s the booking page for an unavailable vehicle', function () {
    stockAlertTenant('sabook', [PlanFeature::StockAlert->value => true], 'sabookplan');
    $vehicle = unavailableVehicle();

    // You can look, but you cannot book.
    Livewire::test('pages::public.vehicle-booking', ['vehicle' => $vehicle])
        ->assertNotFound();
});

it('lists an unavailable vehicle in the fleet, marked as such', function () {
    stockAlertTenant('salisting', [PlanFeature::StockAlert->value => true], 'salistingplan');
    unavailableVehicle();

    // Hiding it from the fleet would leave nothing to click through to.
    Livewire::test('pages::public.vehicle-listing')
        ->assertOk()
        ->assertSee(__('booking.vehicle_unavailable_badge'));
});

// ── Joining ────────────────────────────────────────────────────────────────────

it('records a dateless join from the public panel without creating a customer', function () {
    stockAlertTenant('sajoin', [PlanFeature::StockAlert->value => true], 'sajoinplan');
    $vehicle = unavailableVehicle();

    Livewire::test('pages::public.vehicle-show', ['vehicle' => $vehicle])
        ->set('stockAlertName', 'Ana')
        ->set('stockAlertEmail', 'ana@example.com')
        ->set('stockAlertPhone', '049111222')
        ->call('joinStockAlert')
        ->assertSet('stockAlertJoined', true);

    $entry = WaitlistEntry::query()->sole();

    expect($entry->email)->toBe('ana@example.com')
        ->and($entry->vehicle_id)->toBe($vehicle->id)
        // Null dates are what mark this as a stock alert rather than a waitlist row.
        ->and($entry->start_date)->toBeNull()
        ->and($entry->end_date)->toBeNull()
        ->and($entry->notified_at)->toBeNull()
        // Deliberately no CRM write: an unauthenticated form must not create a
        // Customer, and customers are keyed by phone anyway.
        ->and(Customer::query()->count())->toBe(0);
});

it('shows the same thank-you when someone joins twice, without leaking that they are on the list', function () {
    stockAlertTenant('sadupe', [PlanFeature::StockAlert->value => true], 'sadupeplan');
    $vehicle = unavailableVehicle();

    $join = fn () => Livewire::test('pages::public.vehicle-show', ['vehicle' => $vehicle])
        ->set('stockAlertName', 'Ana')
        ->set('stockAlertEmail', 'dupe@example.com')
        ->call('joinStockAlert')
        ->assertSet('stockAlertJoined', true);

    $join();
    $join();

    // The table's own unique index does nothing here — SQL treats NULL dates as
    // distinct — so this passes only because of the partial index.
    expect(WaitlistEntry::query()->count())->toBe(1);
});

it('throttles repeated joins from the same visitor', function () {
    stockAlertTenant('sathrottle', [PlanFeature::StockAlert->value => true], 'sathrottleplan');
    $vehicle = unavailableVehicle();
    RateLimiter::clear('stock-alert-join:'.$vehicle->id.':127.0.0.1');

    foreach (range(1, 5) as $i) {
        Livewire::test('pages::public.vehicle-show', ['vehicle' => $vehicle])
            ->set('stockAlertName', 'Ana')
            ->set('stockAlertEmail', "spam{$i}@example.com")
            ->call('joinStockAlert')
            ->assertSet('stockAlertJoined', true);
    }

    Livewire::test('pages::public.vehicle-show', ['vehicle' => $vehicle])
        ->set('stockAlertName', 'Ana')
        ->set('stockAlertEmail', 'spam6@example.com')
        ->call('joinStockAlert')
        ->assertSet('stockAlertJoined', false)
        ->assertSet('stockAlertError', __('booking.stock_alert_throttled'));

    expect(WaitlistEntry::query()->count())->toBe(5);
});

// ── Lifecycle ──────────────────────────────────────────────────────────────────

it('notifies pending stock alerts on the sweep when the event never fired', function () {
    Mail::fake();
    [$tenant] = stockAlertTenant('sasweep', [PlanFeature::StockAlert->value => true], 'sasweepplan');

    // Already back on the road, with someone still waiting: the state the app
    // would be left in by a republish event that never reached the queue.
    $vehicle = Vehicle::factory()->create(['is_public' => true, 'status' => VehicleStatus::Available]);
    $entry = WaitlistEntry::factory()->stockAlert()->create(['vehicle_id' => $vehicle->id]);

    (new SweepWaitlistJob($tenant))->handle(app(WaitlistService::class));

    Mail::assertQueued(VehicleBackInStockMail::class, 1);
    expect($entry->fresh()->notified_at)->not->toBeNull();
});

it('does not sweep stock alerts for a vehicle that is still off the road', function () {
    Mail::fake();
    [$tenant] = stockAlertTenant('saswepthold', [PlanFeature::StockAlert->value => true], 'saswepholdplan');
    $vehicle = unavailableVehicle();
    $entry = WaitlistEntry::factory()->stockAlert()->create(['vehicle_id' => $vehicle->id]);

    (new SweepWaitlistJob($tenant))->handle(app(WaitlistService::class));

    Mail::assertNothingQueued();
    expect($entry->fresh()->notified_at)->toBeNull();
});

it('retires stock alerts nobody could still want, and keeps the recent ones', function () {
    stockAlertTenant('sapurge', [PlanFeature::StockAlert->value => true], 'sapurgeplan');
    $vehicle = unavailableVehicle();

    // A dateless entry has no date to expire on, so it ages out on a TTL instead —
    // without this it would wait forever on a vehicle that may never come back.
    $stale = WaitlistEntry::factory()->stockAlert()->create([
        'vehicle_id' => $vehicle->id,
        'created_at' => now()->subDays(91),
    ]);
    $recent = WaitlistEntry::factory()->stockAlert()->create([
        'vehicle_id' => $vehicle->id,
        'created_at' => now()->subDays(30),
    ]);

    app(WaitlistService::class)->purgeExpired();

    expect(WaitlistEntry::query()->find($stale->id))->toBeNull()
        ->and(WaitlistEntry::query()->find($recent->id))->not->toBeNull();
});

it('reports nobody notified when the vehicle is not bookable', function () {
    Mail::fake();
    stockAlertTenant('stocknone', [PlanFeature::StockAlert->value => true], 'stocknoneplan');
    $vehicle = unavailableVehicle();

    WaitlistEntry::factory()->stockAlert()->create(['vehicle_id' => $vehicle->id]);

    expect(app(WaitlistService::class)->notifyStockAlerts($vehicle))->toBe(0);
    Mail::assertNothingQueued();
});

it('reports exactly how many stock alerts it sent', function () {
    Mail::fake();
    stockAlertTenant('stockcount', [PlanFeature::StockAlert->value => true], 'stockcountplan');
    $vehicle = Vehicle::factory()->create(['is_public' => true, 'status' => VehicleStatus::Available]);

    WaitlistEntry::factory()->stockAlert()->count(3)->create(['vehicle_id' => $vehicle->id]);

    expect(app(WaitlistService::class)->notifyStockAlerts($vehicle))->toBe(3);
});

it('keeps the English and Albanian email translations in sync', function () {
    $en = require lang_path('en/emails.php');
    $sq = require lang_path('sq/emails.php');

    expect(array_keys($sq))->toBe(array_keys($en))
        ->and(array_keys($sq['vehicle_back_in_stock']))->toBe(array_keys($en['vehicle_back_in_stock']));
});
