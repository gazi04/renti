<?php

use App\Enums\PlanFeature;
use App\Enums\VehicleStatus;
use App\Filament\Operator\Resources\ServiceRecords\Pages\CreateServiceRecord;
use App\Filament\Operator\Resources\ServiceRecords\Pages\EditServiceRecord;
use App\Filament\Operator\Resources\ServiceRecords\Pages\ListServiceRecords;
use App\Filament\Operator\Resources\ServiceRecords\ServiceRecordResource;
use App\Jobs\ProcessVehicleMaintenanceJob;
use App\Mail\ServiceDueMail;
use App\Models\BlockedDate;
use App\Models\Booking;
use App\Models\Plan;
use App\Models\ServiceRecord;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\AvailabilityService;
use App\Services\BlockedDateService;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

afterEach(fn () => tenancy()->end());

/**
 * @param  array<string, mixed>  $planFeatures
 * @return array{0: Tenant, 1: User}
 */
function maintenanceTenant(string $domain, array $planFeatures = [], ?string $planSlug = null): array
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

it('lets the owner log a service record freely, tenant-scoped, regardless of the plan', function () {
    [$tenant] = maintenanceTenant('maintfree', [PlanFeature::MaintenanceReminders->value => false], 'nomaint');
    $vehicle = Vehicle::factory()->create();

    Livewire::test(CreateServiceRecord::class)
        ->fillForm([
            'vehicle_id' => $vehicle->id,
            'service_type' => 'oil_change',
            'performed_on' => now()->toDateString(),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $record = ServiceRecord::query()->first();
    expect($record->tenant_id)->toBe($tenant->id)
        ->and($record->service_type)->toBe('oil_change');

    Livewire::test(ListServiceRecords::class)->assertSee($vehicle->name);
});

it('gates the service record resource by owner only, not by plan', function () {
    [$tenant] = maintenanceTenant('maintgate', [PlanFeature::MaintenanceReminders->value => false], 'gatemaint');
    expect(ServiceRecordResource::canAccess())->toBeTrue();

    $staff = User::factory()->staff()->create(['tenant_id' => $tenant->id]);
    actingAs($staff);
    expect(ServiceRecordResource::canAccess())->toBeFalse();
});

it('auto-blocks an overdue vehicle when the feature is enabled, making it unavailable', function () {
    [$tenant] = maintenanceTenant('maintblock', [PlanFeature::MaintenanceReminders->value => true], 'withmaint');
    $vehicle = Vehicle::factory()->create();
    $record = ServiceRecord::factory()->overdue()->create(['vehicle_id' => $vehicle->id]);

    (new ProcessVehicleMaintenanceJob($tenant))->handle(app(BlockedDateService::class));

    $record->refresh();
    expect($record->blocked_date_id)->not->toBeNull();

    $blockedDate = BlockedDate::find($record->blocked_date_id);
    expect($blockedDate->reason)->toBe('maintenance');

    $isAvailable = app(AvailabilityService::class)->isAvailable(
        $vehicle->fresh(),
        Carbon::parse($blockedDate->start_date)->addHour(),
        Carbon::parse($blockedDate->start_date)->addHours(2),
    );
    expect($isAvailable)->toBeFalse();
});

it('skips auto-block when an occupying booking already overlaps the due window, and notifies instead', function () {
    // deep-audit finding 04: the sweep must never silently double-book a
    // vehicle it's about to take off the road.
    Notification::fake();

    [$tenant, $owner] = maintenanceTenant('maintconflict', [PlanFeature::MaintenanceReminders->value => true], 'conflictplan');
    $vehicle = Vehicle::factory()->create();
    $record = ServiceRecord::factory()->overdue()->create(['vehicle_id' => $vehicle->id]);

    Booking::factory()->forVehicle($vehicle)->confirmed()->create([
        'start_date' => today(),
        'end_date' => today()->addDay(),
    ]);

    (new ProcessVehicleMaintenanceJob($tenant))->handle(app(BlockedDateService::class));

    $record->refresh();
    expect($record->blocked_date_id)->toBeNull()
        ->and(BlockedDate::query()->count())->toBe(0)
        ->and($vehicle->fresh()->status)->not->toBe(VehicleStatus::UnderMaintenance);

    Notification::assertSentTo(
        $owner,
        DatabaseNotification::class,
        fn (DatabaseNotification $notification): bool => $notification->data['title'] === __('panel.service_overdue_conflict_title')
    );
});

it('sends a reminder once for a due-soon record and does not resend on the next run', function () {
    Mail::fake();

    [$tenant] = maintenanceTenant('maintremind', [PlanFeature::MaintenanceReminders->value => true], 'remindplan');
    $vehicle = Vehicle::factory()->create();
    $record = ServiceRecord::factory()->due()->create(['vehicle_id' => $vehicle->id]);

    (new ProcessVehicleMaintenanceJob($tenant))->handle(app(BlockedDateService::class));

    Mail::assertQueued(ServiceDueMail::class, fn ($m) => $m->serviceRecord->is($record));
    expect($record->fresh()->reminder_sent_at)->not->toBeNull();

    Mail::fake();
    (new ProcessVehicleMaintenanceJob($tenant))->handle(app(BlockedDateService::class));
    Mail::assertNothingQueued();
});

it('skips reminder and auto-block when the plan disables maintenance reminders', function () {
    [$tenant] = maintenanceTenant('maintoff', [PlanFeature::MaintenanceReminders->value => false], 'offplan');
    $vehicle = Vehicle::factory()->create();
    ServiceRecord::factory()->overdue()->create(['vehicle_id' => $vehicle->id]);

    // The command is the gate: it never dispatches the job for this tenant.
    // (This test used to also assert Mail::assertNothingQueued() and a null
    // blocked_date_id "to confirm the job stays inert if called directly" — but
    // it never called the job, and under Queue::fake() nothing could have run,
    // so those assertions held against any job body. See the test below for what
    // the job actually does.)
    Queue::fake();
    $this->artisan('maintenance:process-due')->assertSuccessful();

    Queue::assertNotPushed(ProcessVehicleMaintenanceJob::class);
});

it('does not re-check the plan inside the maintenance job — the command is the only gate', function () {
    [$tenant] = maintenanceTenant('maintjobdirect', [PlanFeature::MaintenanceReminders->value => false], 'offplanjob');
    $vehicle = Vehicle::factory()->create();
    $record = ServiceRecord::factory()->overdue()->create(['vehicle_id' => $vehicle->id]);

    (new ProcessVehicleMaintenanceJob($tenant))->handle(app(BlockedDateService::class));

    // KNOWN GAP, pinned deliberately: the job has no plan check, so a job already
    // queued when a tenant is downgraded still runs the gated behaviour — here it
    // blocks the vehicle and flips it to under-maintenance despite the plan
    // disabling reminders. If a guard is ever added, this test fails,
    // which is the point: the change should be conscious, not incidental.
    expect($record->refresh()->blocked_date_id)->not->toBeNull()
        ->and($vehicle->refresh()->status)->toBe(VehicleStatus::UnderMaintenance);
});

it('clears the vehicle\'s active maintenance block and makes it available when a new service record is logged', function () {
    maintenanceTenant('maintclear', [PlanFeature::MaintenanceReminders->value => true], 'clearplan');
    $vehicle = Vehicle::factory()->underMaintenance()->create();

    $blockedDate = BlockedDate::factory()->forVehicle($vehicle)->create([
        'reason' => 'maintenance',
        'start_date' => now(),
        'end_date' => now()->addDays(3),
    ]);
    // Same type as the record logged below: only a newer record of the SAME
    // service type supersedes the overdue one.
    ServiceRecord::factory()->overdue()->create([
        'vehicle_id' => $vehicle->id,
        'service_type' => 'oil_change',
        'blocked_date_id' => $blockedDate->id,
    ]);

    Livewire::test(CreateServiceRecord::class)
        ->fillForm([
            'vehicle_id' => $vehicle->id,
            'service_type' => 'oil_change',
            'performed_on' => now()->toDateString(),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(BlockedDate::query()->count())->toBe(0)
        ->and(ServiceRecord::query()->whereNotNull('blocked_date_id')->count())->toBe(0);

    expect($vehicle->fresh()->status)->toBe(VehicleStatus::Available);
});

/**
 * Run the sweep the way the scheduler does, then put the test back in tenant
 * context — the job always ends tenancy in its finally block, and the operator
 * panel pages used afterwards need it initialized.
 */
function sweepMaintenance(Tenant $tenant): void
{
    (new ProcessVehicleMaintenanceJob($tenant))->handle(app(BlockedDateService::class));

    tenancy()->initialize($tenant);
}

function logServiceViaPanel(Vehicle $vehicle, string $serviceType): void
{
    Livewire::test(CreateServiceRecord::class)
        ->fillForm([
            'vehicle_id' => $vehicle->id,
            'service_type' => $serviceType,
            'performed_on' => now()->toDateString(),
        ])
        ->call('create')
        ->assertHasNoFormErrors();
}

it('keeps the vehicle released on the next sweep after the overdue service is logged', function () {
    // The regression: logging the service used to unlink the old record while
    // its next_due_on stayed in the past, so the following sweep re-blocked the
    // car — every day, forever.
    [$tenant] = maintenanceTenant('maintloop', [PlanFeature::MaintenanceReminders->value => true], 'loopplan');
    $vehicle = Vehicle::factory()->create();
    $oldRecord = ServiceRecord::factory()->overdue()->create(['vehicle_id' => $vehicle->id, 'service_type' => 'oil_change']);
    sweepMaintenance($tenant);
    logServiceViaPanel($vehicle, 'oil_change');

    sweepMaintenance($tenant);

    expect(BlockedDate::query()->count())->toBe(0);
    expect($vehicle->fresh()->status)->toBe(VehicleStatus::Available);
    expect($oldRecord->fresh()->next_due_on)->not->toBeNull();
});

it('keeps the block when the logged service is a different type than the overdue one', function () {
    [$tenant] = maintenanceTenant('maintothertype', [PlanFeature::MaintenanceReminders->value => true], 'othertypeplan');
    $vehicle = Vehicle::factory()->create();
    $overdue = ServiceRecord::factory()->overdue()->create(['vehicle_id' => $vehicle->id, 'service_type' => 'oil_change']);
    sweepMaintenance($tenant);

    logServiceViaPanel($vehicle, 'tyres');

    expect($overdue->fresh()->blocked_date_id)->not->toBeNull();
    expect($vehicle->fresh()->status)->toBe(VehicleStatus::UnderMaintenance);
});

it('ignores an overdue record that a later-performed service of the same type replaced, even if logged afterwards', function () {
    // Order is by performed_on, not created_at: the stale record is created
    // second, as an operator back-filling last year's history would.
    [$tenant] = maintenanceTenant('maintbackfill', [PlanFeature::MaintenanceReminders->value => true], 'backfillplan');
    $vehicle = Vehicle::factory()->create();
    ServiceRecord::factory()->create([
        'vehicle_id' => $vehicle->id,
        'service_type' => 'oil_change',
        'performed_on' => today(),
        'next_due_on' => today()->addMonths(3),
    ]);
    ServiceRecord::factory()->overdue()->create([
        'vehicle_id' => $vehicle->id,
        'service_type' => 'oil_change',
        'performed_on' => today()->subYear(),
    ]);

    sweepMaintenance($tenant);

    expect(BlockedDate::query()->count())->toBe(0);
    expect($vehicle->fresh()->status)->toBe(VehicleStatus::Available);
});

it('sends no reminder for a record that a newer service of the same type replaced', function () {
    Mail::fake();
    [$tenant] = maintenanceTenant('maintsuperseded', [PlanFeature::MaintenanceReminders->value => true], 'supersededplan');
    $vehicle = Vehicle::factory()->create();
    ServiceRecord::factory()->due()->create(['vehicle_id' => $vehicle->id, 'service_type' => 'tyres']);
    ServiceRecord::factory()->create(['vehicle_id' => $vehicle->id, 'service_type' => 'tyres', 'performed_on' => today()]);

    sweepMaintenance($tenant);

    Mail::assertNothingQueued();
});

it('releases the vehicle when the overdue record\'s due date is moved forward', function () {
    [$tenant] = maintenanceTenant('maintedit', [PlanFeature::MaintenanceReminders->value => true], 'editplan');
    $vehicle = Vehicle::factory()->create();
    $record = ServiceRecord::factory()->overdue()->create(['vehicle_id' => $vehicle->id]);
    sweepMaintenance($tenant);

    Livewire::test(EditServiceRecord::class, ['record' => $record->id])
        ->fillForm(['next_due_on' => today()->addMonth()->toDateString()])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(BlockedDate::query()->count())->toBe(0);
    expect($record->fresh()->blocked_date_id)->toBeNull();
    expect($vehicle->fresh()->status)->toBe(VehicleStatus::Available);
});

it('releases a vehicle the old code left stuck, on the sweep alone', function () {
    // Production-shaped leftover of the bug: the newer same-type record was
    // logged before this fix, so no panel action will ever run the release —
    // the sweep has to heal it on its own.
    [$tenant] = maintenanceTenant('maintheal', [PlanFeature::MaintenanceReminders->value => true], 'healplan');
    $vehicle = Vehicle::factory()->underMaintenance()->create();
    $blockedDate = BlockedDate::factory()->forVehicle($vehicle)->create([
        'reason' => 'maintenance',
        'start_date' => today(),
        'end_date' => today()->addDays(3),
    ]);
    ServiceRecord::factory()->overdue()->create([
        'vehicle_id' => $vehicle->id,
        'service_type' => 'brakes',
        'blocked_date_id' => $blockedDate->id,
    ]);
    ServiceRecord::factory()->create(['vehicle_id' => $vehicle->id, 'service_type' => 'brakes', 'performed_on' => today()]);

    sweepMaintenance($tenant);

    expect(BlockedDate::query()->count())->toBe(0);
    expect($vehicle->fresh()->status)->toBe(VehicleStatus::Available);
});

it('leaves a vehicle the operator put under maintenance by hand untouched when a service is logged', function () {
    maintenanceTenant('maintmanual', [PlanFeature::MaintenanceReminders->value => true], 'manualplan');
    $vehicle = Vehicle::factory()->underMaintenance()->create();

    logServiceViaPanel($vehicle, 'inspection');

    expect($vehicle->fresh()->status)->toBe(VehicleStatus::UnderMaintenance);
});

it('skips a service record whose vehicle was soft-deleted, without fataling the whole sweep', function () {
    Mail::fake();

    [$tenant] = maintenanceTenant('maintdeleted', [PlanFeature::MaintenanceReminders->value => true], 'deletedplan');

    $deletedVehicle = Vehicle::factory()->create();
    $deletedRecord = ServiceRecord::factory()->overdue()->create(['vehicle_id' => $deletedVehicle->id]);
    $deletedVehicle->delete();

    $liveVehicle = Vehicle::factory()->create();
    $liveRecord = ServiceRecord::factory()->overdue()->create(['vehicle_id' => $liveVehicle->id]);

    (new ProcessVehicleMaintenanceJob($tenant))->handle(app(BlockedDateService::class));

    expect($deletedRecord->fresh()->blocked_date_id)->toBeNull();

    $liveRecord->refresh();
    expect($liveRecord->blocked_date_id)->not->toBeNull();
});

/*
 * The two sweeps below deliberately leave TWO records standing where every other
 * test in this file leaves one. The sweep iterates with ->each(), which chunks —
 * and Builder::hydrate() only arms Model::preventLazyLoading() on models from a
 * multi-row result. With a single record the guard is never armed, so reading
 * $record->vehicle inside the loop looks safe; with two it is an implicit lazy
 * load. The soft-delete test above looks like it covers this but does not: its
 * first vehicle is trashed, so whereHas('vehicle') filters the result back down
 * to one row.
 */

it('auto-blocks two overdue records in one sweep without lazy-loading either vehicle', function () {
    [$tenant] = maintenanceTenant('maintpair', [PlanFeature::MaintenanceReminders->value => true], 'pairplan');

    $vehicles = collect(['Golf', 'Passat'])->map(fn (string $name): Vehicle => Vehicle::factory()->create(['name' => $name]));

    $records = $vehicles->map(fn (Vehicle $vehicle): ServiceRecord => ServiceRecord::factory()
        ->overdue()
        ->create(['vehicle_id' => $vehicle->id]));

    // No Mail::fake() needed: autoBlock() sends no mail, which keeps this the
    // deterministic reproducer — the reminder path's queued mail is throttled.
    (new ProcessVehicleMaintenanceJob($tenant))->handle(app(BlockedDateService::class));

    expect(BlockedDate::query()->count())->toBe(2);

    foreach ($records as $record) {
        expect($record->fresh()->blocked_date_id)->not->toBeNull();
    }

    foreach ($vehicles as $vehicle) {
        expect($vehicle->fresh()->status)->toBe(VehicleStatus::UnderMaintenance);
    }
});

it('reminds on two due-soon records in one sweep without lazy-loading either vehicle', function () {
    Mail::fake();

    [$tenant, $owner] = maintenanceTenant('maintpairdue', [PlanFeature::MaintenanceReminders->value => true], 'pairdueplan');

    $records = collect(['Clio', 'Megane'])->map(fn (string $name): ServiceRecord => ServiceRecord::factory()
        ->due()
        ->create(['vehicle_id' => Vehicle::factory()->create(['name' => $name])->id]));

    // Covers the other violating read — the bell-notification body — which the
    // overdue path above never reaches.
    (new ProcessVehicleMaintenanceJob($tenant))->handle(app(BlockedDateService::class));

    foreach ($records as $record) {
        expect($record->fresh()->reminder_sent_at)->not->toBeNull();
    }

    Mail::assertQueued(ServiceDueMail::class, 2);

    expect($owner->notifications()->count())->toBe(2);
});

it('queues maintenance jobs only for active tenants with the feature enabled', function () {
    Queue::fake();

    Plan::factory()->create(['slug' => 'with-maint', 'features' => [PlanFeature::MaintenanceReminders->value => true]]);
    Plan::factory()->create(['slug' => 'no-maint', 'features' => [PlanFeature::MaintenanceReminders->value => false]]);

    Tenant::factory()->withDomain('enabled')->create(['plan' => 'with-maint']);
    Tenant::factory()->withDomain('disabled')->create(['plan' => 'no-maint']);
    Tenant::factory()->withDomain('suspended')->suspended()->create(['plan' => 'with-maint']);

    $this->artisan('maintenance:process-due')->assertSuccessful();

    Queue::assertPushed(ProcessVehicleMaintenanceJob::class, 1);
});

it('configures retries and timeout for maintenance sweep failures', function () {
    $job = new ProcessVehicleMaintenanceJob(Tenant::factory()->make());

    expect($job->tries)->toBe(3)
        ->and($job->timeout)->toBe(60)
        ->and($job->backoff())->toBe([60, 300, 900]);
});

it('logs tenant context when the maintenance sweep job fails permanently', function () {
    $tenant = Tenant::factory()->create();

    Log::spy();

    (new ProcessVehicleMaintenanceJob($tenant))->failed(new Exception('boom'));

    Log::shouldHaveReceived('error')->once()->withArgs(
        fn (string $message, array $context) => $message === 'Vehicle maintenance sweep failed'
            && $context['tenant_id'] === $tenant->id
    );
});
