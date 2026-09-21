<?php

use App\Enums\BookingStatus;
use App\Filament\Operator\Resources\Bookings\Pages\ListBookings;
use App\Models\Booking;
use App\Models\Contract;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\BookingService;
use App\Services\Media\MediaFileResolver;
use App\Services\RentalAgreementService;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

afterEach(function () {
    tenancy()->end();
});

/**
 * @return array{0: Tenant, 1: User, 2: Booking}
 */
function agreementSetup(string $domain = 'acme', BookingStatus $status = BookingStatus::Confirmed): array
{
    $tenant = Tenant::factory()->withDomain($domain)->create();
    $operator = agreementOperatorFor($tenant);

    tenancy()->initialize($tenant);
    // Re-fake after initializing: FilesystemTenancyBootstrapper overwrites the 'local'
    // disk root on initialize, silently undoing any Storage::fake() called before it —
    // without this, tests write real PDFs to storage/tenant{id}/... on every run.
    Storage::fake();
    Filament::setCurrentPanel(Filament::getPanel('operator'));
    actingAs($operator);

    $vehicle = Vehicle::factory()->create(['daily_rate' => 50, 'weekly_rate' => null, 'monthly_rate' => null]);
    $booking = Booking::factory()->forVehicle($vehicle)->state(['status' => $status, 'locale' => 'sq'])->create();

    return [$tenant, $operator, $booking];
}

function agreementOperatorFor(Tenant $tenant): User
{
    $user = new User;
    $user->forceFill([
        'tenant_id' => $tenant->id,
        'role' => 'operator',
        'name' => 'Operator',
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
    ])->save();

    return $user;
}

// ── Generate ──────────────────────────────────────────────────────────────────

it('generates a PDF stored at the tenant path and creates a Contract row', function () {
    Storage::fake();

    [$tenant, , $booking] = agreementSetup();

    $service = app(RentalAgreementService::class);
    $contract = $service->generate($booking);

    $expectedPath = "tenants/{$tenant->id}/contracts/{$booking->reference}.pdf";

    expect($contract)->toBeInstanceOf(Contract::class)
        ->and($contract->booking_id)->toBe($booking->id)
        ->and($contract->tenant_id)->toBe($tenant->id)
        ->and($contract->path)->toBe($expectedPath)
        ->and($contract->generated_at)->not->toBeNull()
        // A5: the dead `token` column was dropped — it must not come back.
        ->and(Schema::hasColumn('contracts', 'token'))->toBeFalse();

    Storage::assertExists($expectedPath);
});

it('generates a non-empty PDF with %PDF header', function () {
    Storage::fake();

    [, , $booking] = agreementSetup();

    $contract = app(RentalAgreementService::class)->generate($booking);

    $content = Storage::get($contract->path);
    expect($content)->toStartWith('%PDF');
});

it('generates the agreement for a booking whose vehicle was later soft-deleted', function () {
    Storage::fake();

    [, , $booking] = agreementSetup();
    $booking->vehicle->delete();

    $contract = app(RentalAgreementService::class)->generate($booking->fresh());

    Storage::assertExists($contract->path);
});

// ── Operator logo ─────────────────────────────────────────────────────────────

it('embeds the operator logo as a data URI when the media disk is remote', function () {
    // Production runs MEDIA_DISK=s3; the logo used to be pulled via a local
    // filesystem path (getFirstMediaPath + file_exists), which silently dropped
    // it. It must now be read through the disk layer and inlined for dompdf,
    // which has enable_remote => false.
    config(['media-library.disk_name' => 's3']);

    $tenant = Tenant::factory()->withDomain('logo-pdf')->create();
    tenancy()->initialize($tenant);
    Storage::fake();
    Storage::fake('s3');
    actingAs(agreementOperatorFor($tenant));

    $tenant->addMedia(UploadedFile::fake()->image('logo.png', 200, 200))->toMediaCollection('logo');

    $vehicle = Vehicle::factory()->create(['daily_rate' => 50]);
    $booking = Booking::factory()->forVehicle($vehicle)->state(['locale' => 'sq'])->create();

    $contract = app(RentalAgreementService::class)->generate($booking);

    expect(Storage::get($contract->path))->toStartWith('%PDF');

    $rendered = view('pdf.rental-agreement', [
        'booking' => $booking->load('vehicle'),
        'logoDataUri' => app(MediaFileResolver::class)
            ->dataUri($tenant->getFirstMedia('logo'), 'thumb'),
    ])->render();

    expect($rendered)->toContain('<img src="data:image/')
        ->and($rendered)->not->toContain('class="operator-name"');
});

it('falls back to the operator name when there is no logo', function () {
    [$tenant, , $booking] = agreementSetup();

    $rendered = view('pdf.rental-agreement', [
        'booking' => $booking->load('vehicle'),
        'logoDataUri' => null,
    ])->render();

    expect($rendered)->toContain('class="operator-name"')
        ->and($rendered)->toContain($tenant->name);
});

// ── Idempotency ───────────────────────────────────────────────────────────────

it('reuses existing Contract on second generate without force', function () {
    Storage::fake();

    [, , $booking] = agreementSetup();
    $service = app(RentalAgreementService::class);

    $first = $service->generate($booking);
    $second = $service->generate($booking);

    expect($second->id)->toBe($first->id)
        ->and(Contract::count())->toBe(1);
});

it('regenerates and updates generated_at when force=true', function () {
    Storage::fake();

    [, , $booking] = agreementSetup();
    $service = app(RentalAgreementService::class);

    $first = $service->generate($booking);
    $originalTime = $first->generated_at->toDateTimeString();

    $this->travel(2)->seconds();

    $second = $service->generate($booking, force: true);

    expect($second->id)->toBe($first->id)
        ->and($second->generated_at->toDateTimeString())->not->toBe($originalTime)
        ->and(Contract::count())->toBe(1);
});

it('force-regenerates an existing agreement PDF when the booking is moved', function () {
    // BookingService::move() (deep-audit finding 08) must not leave a stale
    // agreement cached: the PDF is idempotent by file existence, not content,
    // so an unforced generate() after a move would silently keep serving the
    // pre-move dates.
    Storage::fake();

    [, , $booking] = agreementSetup();
    $agreementService = app(RentalAgreementService::class);

    $first = $agreementService->generate($booking);
    $originalTime = $first->generated_at->toDateTimeString();

    $this->travel(2)->seconds();

    app(BookingService::class)->move($booking, [
        'vehicle_id' => $booking->vehicle_id,
        'start_date' => $booking->start_date->addDays(10)->toDateTimeString(),
        'end_date' => $booking->end_date->addDays(10)->toDateTimeString(),
    ]);

    $second = $booking->fresh()->contract;

    expect($second)->not->toBeNull()
        ->and($second->id)->toBe($first->id)
        ->and($second->generated_at->toDateTimeString())->not->toBe($originalTime)
        ->and(Contract::count())->toBe(1);
});

// ── Bilingual ─────────────────────────────────────────────────────────────────

it('renders Albanian heading when booking locale is sq', function () {
    Storage::fake();

    $tenant = Tenant::factory()->withDomain('sq-test')->create();
    tenancy()->initialize($tenant);
    Storage::fake();
    actingAs(agreementOperatorFor($tenant));
    $vehicle = Vehicle::factory()->create(['daily_rate' => 50]);
    $booking = Booking::factory()->forVehicle($vehicle)->state(['locale' => 'sq'])->create();

    app(RentalAgreementService::class)->generate($booking);

    App::setLocale('sq');
    $rendered = view('pdf.rental-agreement', ['booking' => $booking->load('vehicle')])->render();

    expect($rendered)->toContain('KONTRATË QIRAJE AUTOMJETI');
});

it('renders English heading when booking locale is en', function () {
    Storage::fake();

    $tenant = Tenant::factory()->withDomain('en-test')->create();
    tenancy()->initialize($tenant);
    Storage::fake();
    actingAs(agreementOperatorFor($tenant));
    $vehicle = Vehicle::factory()->create(['daily_rate' => 50]);
    $booking = Booking::factory()->forVehicle($vehicle)->state(['locale' => 'en'])->create();

    app(RentalAgreementService::class)->generate($booking);

    App::setLocale('en');
    $rendered = view('pdf.rental-agreement', ['booking' => $booking->load('vehicle')])->render();

    expect($rendered)->toContain('VEHICLE RENTAL AGREEMENT');
});

it('restores the previous app locale after generating a PDF in a different booking locale', function () {
    Storage::fake();

    $tenant = Tenant::factory()->withDomain('localerestore')->create();
    tenancy()->initialize($tenant);
    Storage::fake();
    actingAs(agreementOperatorFor($tenant));
    $vehicle = Vehicle::factory()->create(['daily_rate' => 50]);
    $booking = Booking::factory()->forVehicle($vehicle)->state(['locale' => 'en'])->create();

    App::setLocale('sq');

    app(RentalAgreementService::class)->generate($booking);

    expect(App::getLocale())->toBe('sq');
});

// ── Operator action ───────────────────────────────────────────────────────────

it('Download agreement action is visible for a Confirmed booking', function () {
    Storage::fake();

    [, , $booking] = agreementSetup(status: BookingStatus::Confirmed);

    Livewire::test(ListBookings::class)
        ->assertTableActionExists('agreement', record: $booking);
});

it('Download agreement action is hidden for a Pending booking', function () {
    Storage::fake();

    [, , $booking] = agreementSetup(status: BookingStatus::Pending);

    Livewire::test(ListBookings::class)
        ->assertTableActionHidden('agreement', record: $booking);
});

it('Download agreement action is hidden for a Cancelled booking', function () {
    Storage::fake();

    [, , $booking] = agreementSetup(status: BookingStatus::Cancelled);

    Livewire::test(ListBookings::class)
        ->assertTableActionHidden('agreement', record: $booking);
});

// ── Signed URL ────────────────────────────────────────────────────────────────

it('valid signed agreement URL streams the PDF with 200', function () {
    Storage::fake();

    $tenant = Tenant::factory()->withDomain('signed-test')->create();
    tenancy()->initialize($tenant);
    Storage::fake();
    $vehicle = Vehicle::factory()->create(['daily_rate' => 50]);
    $booking = Booking::factory()->forVehicle($vehicle)->confirmed()->create();

    app(RentalAgreementService::class)->generate($booking);
    tenancy()->end();

    URL::forceRootUrl(tenant_url('signed-test'));
    $url = URL::temporarySignedRoute('agreement.download', now()->addDays(7), ['booking' => $booking->reference]);

    $this->get($url)->assertOk();
});

it('expired signed URL returns 404', function () {
    Storage::fake();

    $tenant = Tenant::factory()->withDomain('expired-test')->create();
    tenancy()->initialize($tenant);
    Storage::fake();
    $vehicle = Vehicle::factory()->create(['daily_rate' => 50]);
    $booking = Booking::factory()->forVehicle($vehicle)->confirmed()->create();
    tenancy()->end();

    URL::forceRootUrl(tenant_url('expired-test'));
    $url = URL::temporarySignedRoute('agreement.download', now()->subMinutes(1), ['booking' => $booking->reference]);

    $this->get($url)->assertNotFound();
});

it('tampered signed URL returns 404', function () {
    Storage::fake();

    $tenant = Tenant::factory()->withDomain('tampered-test')->create();
    tenancy()->initialize($tenant);
    Storage::fake();
    $vehicle = Vehicle::factory()->create(['daily_rate' => 50]);
    $booking = Booking::factory()->forVehicle($vehicle)->confirmed()->create();
    tenancy()->end();

    URL::forceRootUrl(tenant_url('tampered-test'));
    $url = URL::temporarySignedRoute('agreement.download', now()->addDays(7), ['booking' => $booking->reference]);

    $this->get($url.'&tampered=1')->assertNotFound();
});

it('valid signed agreement URL for a Pending booking returns 404', function () {
    Storage::fake();

    $tenant = Tenant::factory()->withDomain('pending-agreement-test')->create();
    tenancy()->initialize($tenant);
    Storage::fake();
    $vehicle = Vehicle::factory()->create(['daily_rate' => 50]);
    $booking = Booking::factory()->forVehicle($vehicle)->create(); // default status: Pending
    tenancy()->end();

    URL::forceRootUrl(tenant_url('pending-agreement-test'));
    $url = URL::temporarySignedRoute('agreement.download', now()->addDays(7), ['booking' => $booking->reference]);

    $this->get($url)->assertNotFound();
});

it('valid signed agreement URL for a Cancelled booking returns 404', function () {
    Storage::fake();

    $tenant = Tenant::factory()->withDomain('cancelled-agreement-test')->create();
    tenancy()->initialize($tenant);
    Storage::fake();
    $vehicle = Vehicle::factory()->create(['daily_rate' => 50]);
    $booking = Booking::factory()->forVehicle($vehicle)->cancelled()->create();
    tenancy()->end();

    URL::forceRootUrl(tenant_url('cancelled-agreement-test'));
    $url = URL::temporarySignedRoute('agreement.download', now()->addDays(7), ['booking' => $booking->reference]);

    $this->get($url)->assertNotFound();
});

it('generates PDF on demand when signed route hit and no stored file exists', function () {
    Storage::fake();

    $tenant = Tenant::factory()->withDomain('ondemand-test')->create();
    tenancy()->initialize($tenant);
    Storage::fake();
    $vehicle = Vehicle::factory()->create(['daily_rate' => 50]);
    $booking = Booking::factory()->forVehicle($vehicle)->confirmed()->create();
    tenancy()->end();

    expect(Contract::where('booking_id', $booking->id)->exists())->toBeFalse();

    URL::forceRootUrl(tenant_url('ondemand-test'));
    $url = URL::temporarySignedRoute('agreement.download', now()->addDays(7), ['booking' => $booking->reference]);
    $this->get($url)->assertOk();

    expect(Contract::where('booking_id', $booking->id)->exists())->toBeTrue();
});

// ── Tenant isolation ──────────────────────────────────────────────────────────

it('booking from tenant A is invisible to tenant B via global scope', function () {
    Storage::fake();

    $tenantA = Tenant::factory()->withDomain('iso-a')->create();
    tenancy()->initialize($tenantA);
    Storage::fake();
    $vehicleA = Vehicle::factory()->create(['daily_rate' => 50]);
    $bookingA = Booking::factory()->forVehicle($vehicleA)->confirmed()->create();
    app(RentalAgreementService::class)->generate($bookingA);
    tenancy()->end();

    $tenantB = Tenant::factory()->withDomain('iso-b')->create();
    $operatorB = agreementOperatorFor($tenantB);
    tenancy()->initialize($tenantB);
    Filament::setCurrentPanel(Filament::getPanel('operator'));
    actingAs($operatorB);

    expect(Booking::find($bookingA->id))->toBeNull();
    expect(Contract::where('booking_id', $bookingA->id)->first())->toBeNull();
});
