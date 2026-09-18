<?php

use App\Enums\VehicleStatus;
use App\Filament\Operator\Resources\Vehicles\Pages\CreateVehicle;
use App\Filament\Operator\Resources\Vehicles\Pages\ListVehicles;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

use function Pest\Laravel\actingAs;

afterEach(function () {
    tenancy()->end();
});

/**
 * Create an active tenant on the given subdomain plus an operator user for it.
 *
 * @return array{0: Tenant, 1: User}
 */
function fleetOperator(string $domain): array
{
    $tenant = Tenant::factory()->withDomain($domain)->create();

    $user = new User;
    $user->forceFill([
        'tenant_id' => $tenant->id,
        'role' => 'operator',
        'name' => 'Operator',
        'email' => fake()->unique()->safeEmail(),
        'password' => 'password',
        'email_verified_at' => now(),
    ])->save();

    return [$tenant, $user];
}

/**
 * Minimum required fields to submit the vehicle form.
 *
 * @return array<string, mixed>
 */
function vehicleFormData(array $overrides = []): array
{
    return array_merge([
        'name' => 'VW Golf',
        'category' => 'sedan',
        'year' => 2022,
        'fuel_type' => 'petrol',
        'transmission' => 'manual',
        'seats' => 5,
        'daily_rate' => 45,
        'status' => 'available',
        'is_public' => true,
    ], $overrides);
}

it('auto-fills tenant_id when a vehicle is created in a tenant context', function () {
    [$tenant] = fleetOperator('ardi.localhost');
    tenancy()->initialize($tenant);

    $vehicle = Vehicle::factory()->create();

    expect($vehicle->tenant_id)->toBe($tenant->id);
});

it('scopes vehicles to the current tenant', function () {
    [$tenantA] = fleetOperator('a.localhost');
    [$tenantB] = fleetOperator('b.localhost');

    tenancy()->initialize($tenantA);
    Vehicle::factory()->count(2)->create();
    tenancy()->end();

    tenancy()->initialize($tenantB);
    Vehicle::factory()->count(3)->create();
    expect(Vehicle::count())->toBe(3);
    tenancy()->end();

    tenancy()->initialize($tenantA);
    expect(Vehicle::count())->toBe(2);
});

it('defaults a new vehicle to public and available', function () {
    [$tenant] = fleetOperator('ardi.localhost');
    tenancy()->initialize($tenant);

    $vehicle = Vehicle::create([
        'name' => 'Toyota Corolla',
        'category' => 'sedan',
        'year' => 2023,
        'fuel_type' => 'petrol',
        'transmission' => 'automatic',
        'seats' => 5,
        'daily_rate' => 40,
    ]);

    expect($vehicle->is_public)->toBeTrue()
        ->and($vehicle->status)->toBe(VehicleStatus::Available);
});

it('soft deletes a vehicle but keeps the row', function () {
    [$tenant] = fleetOperator('ardi.localhost');
    tenancy()->initialize($tenant);
    $vehicle = Vehicle::factory()->create();

    $vehicle->delete();

    expect(Vehicle::count())->toBe(0)
        ->and(Vehicle::withTrashed()->count())->toBe(1);
});

it('creates a vehicle with custom fields through the operator panel form', function () {
    [$tenant, $operator] = fleetOperator('ardi.localhost');
    tenancy()->initialize($tenant);
    Filament::setCurrentPanel(Filament::getPanel('operator'));
    actingAs($operator);

    Livewire::test(CreateVehicle::class)
        ->fillForm(vehicleFormData([
            'name' => 'BMW 320i',
            'custom_fields' => [['label' => 'GPS', 'value' => 'Included']],
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    $vehicle = Vehicle::firstWhere('name', 'BMW 320i');

    expect($vehicle)->not->toBeNull()
        ->and($vehicle->tenant_id)->toBe($tenant->id)
        ->and($vehicle->custom_fields)->toBe([['label' => 'GPS', 'value' => 'Included']]);
});

// ─── Pricing-tier warning (deep-audit finding 03) ────────────────────────────
// Informational only — PricingService::selectRate() always charges the
// cheapest applicable tier, so an inconsistent rate never gets billed. This
// just helps the operator notice before it confuses a customer.

it('warns when the weekly rate is pricier than the daily rate for the same span', function () {
    [$tenant, $operator] = fleetOperator('ardi.localhost');
    tenancy()->initialize($tenant);
    Filament::setCurrentPanel(Filament::getPanel('operator'));
    actingAs($operator);

    Livewire::test(CreateVehicle::class)
        ->fillForm(vehicleFormData(['daily_rate' => 40, 'weekly_rate' => 300])) // > 7 * 40
        ->assertSee(__('panel.weekly_rate_pricier_warning'));
});

it('warns when the monthly rate is pricier than the daily rate for the same span', function () {
    [$tenant, $operator] = fleetOperator('ardi.localhost');
    tenancy()->initialize($tenant);
    Filament::setCurrentPanel(Filament::getPanel('operator'));
    actingAs($operator);

    Livewire::test(CreateVehicle::class)
        ->fillForm(vehicleFormData(['daily_rate' => 40, 'monthly_rate' => 1300])) // > 30 * 40
        ->assertSee(__('panel.monthly_rate_pricier_warning'));
});

it('shows no pricing warning for rates consistent with the daily rate', function () {
    [$tenant, $operator] = fleetOperator('ardi.localhost');
    tenancy()->initialize($tenant);
    Filament::setCurrentPanel(Filament::getPanel('operator'));
    actingAs($operator);

    Livewire::test(CreateVehicle::class)
        // Matches VehicleFactory's own convention: weekly = 6x daily, monthly = 24x daily.
        ->fillForm(vehicleFormData(['daily_rate' => 40, 'weekly_rate' => 240, 'monthly_rate' => 960]))
        ->assertDontSee(__('panel.weekly_rate_pricier_warning'))
        ->assertDontSee(__('panel.monthly_rate_pricier_warning'));
});

it('rejects a duplicate plate within the same tenant', function () {
    [$tenant, $operator] = fleetOperator('ardi.localhost');
    tenancy()->initialize($tenant);
    Filament::setCurrentPanel(Filament::getPanel('operator'));
    actingAs($operator);

    Vehicle::factory()->create(['plate' => 'AA-123-BB']);

    Livewire::test(CreateVehicle::class)
        ->fillForm(vehicleFormData(['name' => 'Second Car', 'plate' => 'AA-123-BB']))
        ->call('create')
        ->assertHasFormErrors(['plate']);

    expect(Vehicle::where('plate', 'AA-123-BB')->count())->toBe(1);
});

it('allows the same plate across different tenants', function () {
    [$tenantA] = fleetOperator('a.localhost');
    tenancy()->initialize($tenantA);
    Vehicle::factory()->create(['plate' => 'AA-123-BB']);
    tenancy()->end();

    [$tenantB, $operatorB] = fleetOperator('b.localhost');
    tenancy()->initialize($tenantB);
    Filament::setCurrentPanel(Filament::getPanel('operator'));
    actingAs($operatorB);

    Livewire::test(CreateVehicle::class)
        ->fillForm(vehicleFormData(['name' => 'Other Tenant Car', 'plate' => 'AA-123-BB']))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Vehicle::where('plate', 'AA-123-BB')->count())->toBe(1);
});

it('leaves plate optional', function () {
    [$tenant] = fleetOperator('ardi.localhost');
    tenancy()->initialize($tenant);

    $vehicle = Vehicle::factory()->create(['plate' => null]);

    expect($vehicle->plate)->toBeNull();
});

it('attaches an uploaded photo on a subdomain and generates a webp conversion', function () {
    [$tenant, $operator] = fleetOperator('ardi.localhost');
    tenancy()->initialize($tenant);
    Storage::fake('public');
    Filament::setCurrentPanel(Filament::getPanel('operator'));
    actingAs($operator);

    Livewire::test(CreateVehicle::class)
        ->fillForm(vehicleFormData([
            'name' => 'Audi A4',
            'photos' => [UploadedFile::fake()->image('car.jpg', 800, 600)],
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    $vehicle = Vehicle::firstWhere('name', 'Audi A4');
    $media = $vehicle->getFirstMedia('vehicle_photos');

    expect($vehicle->getMedia('vehicle_photos'))->toHaveCount(1)
        ->and($media->hasGeneratedConversion('web'))->toBeTrue();
});

it('stores vehicle photos under a tenants/{tenant_id}/vehicle_photos/{media_id}/ path, not a bare media-ID folder', function () {
    [$tenant] = fleetOperator('ardi.localhost');
    tenancy()->initialize($tenant);
    Storage::fake('public');

    $vehicle = Vehicle::factory()->create();
    $media = $vehicle->addMedia(UploadedFile::fake()->image('car.jpg', 800, 600))
        ->toMediaCollection('vehicle_photos');

    $expectedPrefix = "tenants/{$tenant->id}/vehicle_photos/{$media->id}/";

    expect($media->getPath())->toContain($expectedPrefix)
        ->and($media->getPathRelativeToRoot())->toStartWith($expectedPrefix);
});

it('resolves the storage path for media read back from the database, without lazy loading its owner', function () {
    [$tenant] = fleetOperator('ardi.localhost');
    tenancy()->initialize($tenant);
    Storage::fake('public');

    $vehicle = Vehicle::factory()->create();

    // Two photos, deliberately: Builder::hydrate() only arms preventLazyLoading()
    // on models from a multi-row result, so a single-photo vehicle would never
    // catch an implicit lazy load here.
    foreach (['front.jpg', 'rear.jpg'] as $file) {
        $vehicle->addMedia(UploadedFile::fake()->image($file, 800, 600))
            ->toMediaCollection('vehicle_photos');
    }

    // Straight from the DB, so the `model` relation is not preloaded the way it is
    // on the instances addMedia() hands back — the state every Filament photo field
    // and image column starts from.
    $media = Media::query()->where('model_id', $vehicle->getKey())->get();

    expect($media)->toHaveCount(2);

    foreach ($media as $photo) {
        expect($photo->getPathRelativeToRoot())
            ->toStartWith("tenants/{$tenant->id}/vehicle_photos/{$photo->id}/");
    }
});

it('renders the vehicle list for a vehicle that already has photos', function () {
    [$tenant, $operator] = fleetOperator('ardi.localhost');
    tenancy()->initialize($tenant);
    Storage::fake('public');
    Filament::setCurrentPanel(Filament::getPanel('operator'));
    actingAs($operator);

    $vehicle = Vehicle::factory()->create();

    // Two, for the same multi-row hydration reason as the test above.
    foreach (['front.jpg', 'rear.jpg'] as $file) {
        $vehicle->addMedia(UploadedFile::fake()->image($file, 800, 600))
            ->toMediaCollection('vehicle_photos');
    }

    // The cover image column asks each media row for its URL, which runs the
    // tenant-aware path generator over media straight out of the database.
    Livewire::test(ListVehicles::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$vehicle]);
});

it('stores vehicle photos on the configured media-library disk, not a hardcoded one', function () {
    // Production (Laravel Cloud) sets MEDIA_DISK=s3 so uploads survive deploys.
    // The collection must follow config, not the literal 'public' it used to pin.
    config(['media-library.disk_name' => 's3']);

    [$tenant] = fleetOperator('ardi.localhost');
    tenancy()->initialize($tenant);
    Storage::fake('s3');

    $vehicle = Vehicle::factory()->create();

    foreach (['front.jpg', 'rear.jpg'] as $file) {
        $vehicle->addMedia(UploadedFile::fake()->image($file, 800, 600))
            ->toMediaCollection('vehicle_photos');
    }

    foreach ($vehicle->getMedia('vehicle_photos') as $media) {
        expect($media->disk)->toBe('s3')
            ->and(Storage::disk('s3')->exists($media->getPathRelativeToRoot()))->toBeTrue()
            ->and($media->hasGeneratedConversion('web'))->toBeTrue()
            ->and($media->getFullUrl('web'))->toContain("tenants/{$tenant->id}/vehicle_photos/");
    }
});

it('stores the operator logo on the configured media-library disk', function () {
    config(['media-library.disk_name' => 's3']);

    [$tenant] = fleetOperator('ardi.localhost');
    tenancy()->initialize($tenant);
    Storage::fake('s3');

    $media = $tenant->addMedia(UploadedFile::fake()->image('logo.png', 200, 200))
        ->toMediaCollection('logo');

    expect($media->disk)->toBe('s3')
        ->and(Storage::disk('s3')->exists($media->getPathRelativeToRoot()))->toBeTrue();
});

it('404s a vehicle photo URL when the media disk is the private local disk, not the public one', function () {
    // Pins the exact production incident: .env.example used to default MEDIA_DISK to
    // 'local', and Spatie's DefaultUrlGenerator emits the same unsigned /storage/{path}
    // URL no matter which disk is configured. Laravel's ServeFile only serves that
    // unsigned request when the disk's own config visibility is 'public'; 'local' has
    // none, so every vehicle photo 404s storefront-wide with nothing erroring
    // server-side. Real routed HTTP request, not an object-level assertion — a
    // config-only check would not have caught this; the four disk-selecting call sites
    // all "look" correct in code review.
    config(['media-library.disk_name' => 'local']);

    [$tenant] = fleetOperator('ardi.localhost');
    tenancy()->initialize($tenant);
    Storage::fake('local');

    $vehicle = Vehicle::factory()->create();
    $media = $vehicle->addMedia(UploadedFile::fake()->image('front.jpg', 800, 600))
        ->toMediaCollection('vehicle_photos');

    $this->get($media->getFullUrl())->assertNotFound();
});

it('stores vehicle photos on the public disk when media-library.disk_name is public', function () {
    // The two tests above pin the S3 (production) path; this pins the *fixed default*
    // itself, since neither existing test in this file exercises 'public' explicitly.
    config(['media-library.disk_name' => 'public']);

    [$tenant] = fleetOperator('ardi.localhost');
    tenancy()->initialize($tenant);
    Storage::fake('public');

    $vehicle = Vehicle::factory()->create();
    $media = $vehicle->addMedia(UploadedFile::fake()->image('front.jpg', 800, 600))
        ->toMediaCollection('vehicle_photos');

    expect($media->disk)->toBe('public')
        ->and(config()->string('filesystems.disks.public.visibility'))->toBe('public')
        ->and(Storage::disk('public')->exists($media->getPathRelativeToRoot()))->toBeTrue();
});

it('keeps vehicle photos from different tenants under separate storage roots', function () {
    [$tenantA] = fleetOperator('a.localhost');
    [$tenantB] = fleetOperator('b.localhost');

    tenancy()->initialize($tenantA);
    Storage::fake('public');
    $vehicleA = Vehicle::factory()->create();
    $mediaA = $vehicleA->addMedia(UploadedFile::fake()->image('a.jpg'))->toMediaCollection('vehicle_photos');
    $rootA = $mediaA->getPath();
    tenancy()->end();

    tenancy()->initialize($tenantB);
    Storage::fake('public');
    $vehicleB = Vehicle::factory()->create();
    $mediaB = $vehicleB->addMedia(UploadedFile::fake()->image('b.jpg'))->toMediaCollection('vehicle_photos');
    $rootB = $mediaB->getPath();

    expect($rootA)->not->toBe($rootB)
        ->and($rootA)->toContain((string) $tenantA->id)
        ->and($rootB)->toContain((string) $tenantB->id);
});
