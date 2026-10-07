<?php

use App\Ai\Agents\BusinessSummaryAgent;
use App\Ai\Agents\PricingSuggestionAgent;
use App\Ai\Agents\VehicleListingAgent;
use App\Enums\PlanFeature;
use App\Exceptions\AiRequestFailedException;
use App\Filament\Operator\Resources\Vehicles\Pages\CreateVehicle;
use App\Filament\Operator\Resources\Vehicles\Pages\EditVehicle;
use App\Filament\Operator\Widgets\BusinessSummaryWidget;
use App\Jobs\GenerateBusinessSummaryJob;
use App\Models\AiBusinessSummary;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Ai\BusinessSummaryGenerator;
use App\Services\Ai\VehicleListingWriter;
use Filament\Facades\Filament;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

afterEach(function () {
    tenancy()->end();
});

/**
 * Active tenant (with a plan whose features are $planFeatures) + a logged-in
 * operator, inside tenant context and the operator panel.
 *
 * @param  array<string, mixed>  $planFeatures
 * @return array{0: Tenant, 1: User}
 */
function aiOperator(string $domain, array $planFeatures = [], string $role = 'operator'): array
{
    Plan::factory()->create(['slug' => 'ai-plan', 'features' => $planFeatures]);

    $tenant = Tenant::factory()->withDomain($domain)->create(['plan' => 'ai-plan']);

    $operator = new User;
    $operator->forceFill([
        'tenant_id' => $tenant->id,
        'role' => $role,
        'name' => 'Operator',
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
    ])->save();

    tenancy()->initialize($tenant);
    Filament::setCurrentPanel(Filament::getPanel('operator'));
    actingAs($operator);

    return [$tenant, $operator];
}

it('hides the listing writer action without the plan feature', function () {
    aiOperator('nolisting');
    $vehicle = Vehicle::factory()->create();

    Livewire::test(EditVehicle::class, ['record' => $vehicle->getRouteKey()])
        ->assertFormComponentActionDoesNotExist('description.en', 'generateDescription');
});

it('hides the listing writer for a plan that explicitly disables it', function () {
    // The test above passes a plan with features => [], so it only exercises
    // the AI default() === false branch. This pins the explicit-false path.
    aiOperator('nolistingexplicit', [PlanFeature::AiListingWriter->value => false]);
    $vehicle = Vehicle::factory()->create();

    Livewire::test(EditVehicle::class, ['record' => $vehicle->getRouteKey()])
        ->assertFormComponentActionDoesNotExist('description.en', 'generateDescription');
});

it('hides the pricing suggestion action when the plan disables it', function () {
    aiOperator('nopricing', [PlanFeature::AiPricingSuggestions->value => false]);

    // A real record matters: suggestPrice's predicate is
    // `$record !== null && allowsFeature(...)`. Testing on CreateVehicle would
    // pass on the record half and prove nothing about the plan half.
    $vehicle = Vehicle::factory()->create();

    Livewire::test(EditVehicle::class, ['record' => $vehicle->getRouteKey()])
        ->assertFormComponentActionDoesNotExist('daily_rate', 'suggestPrice');
});

it('shows the listing writer action with the plan feature', function () {
    aiOperator('haslisting', [PlanFeature::AiListingWriter->value => true]);
    $vehicle = Vehicle::factory()->create();

    Livewire::test(EditVehicle::class, ['record' => $vehicle->getRouteKey()])
        ->assertFormComponentActionVisible('description.en', 'generateDescription');
});

it('sends vehicle photos as attachments when the media disk is remote', function () {
    // photoAttachments() used to read a local path (Media::getPath + is_file),
    // which returns nothing once media lives on S3. It now streams bytes off the
    // configured disk into temp files the SDK reads by path.
    config(['media-library.disk_name' => 's3']);
    aiOperator('s3photos', [PlanFeature::AiListingWriter->value => true]);
    Storage::fake('s3');

    $vehicle = Vehicle::factory()->create();

    foreach (['a.jpg', 'b.jpg'] as $file) {
        $vehicle->addMedia(UploadedFile::fake()->image($file, 400, 300))
            ->toMediaCollection('vehicle_photos');
    }

    $attachmentCount = null;
    VehicleListingAgent::fake([
        function (string $prompt, $attachments) use (&$attachmentCount) {
            $attachmentCount = $attachments->count();

            return ['en' => 'A tidy hatchback.', 'sq' => 'Një hatchback i rregullt.'];
        },
    ]);

    $result = app(VehicleListingWriter::class)->write(['name' => 'Golf'], $vehicle->fresh());

    expect($result)->toBe(['en' => 'A tidy hatchback.', 'sq' => 'Një hatchback i rregullt.'])
        ->and($attachmentCount)->toBe(2);
});

it('fills both description languages from the AI response', function () {
    aiOperator('fills', [PlanFeature::AiListingWriter->value => true]);
    $vehicle = Vehicle::factory()->create();

    VehicleListingAgent::fake([[
        'en' => 'A crisp, reliable ride for city trips.',
        'sq' => 'Një makinë e besueshme për udhëtimet në qytet.',
    ]]);

    Livewire::test(EditVehicle::class, ['record' => $vehicle->getRouteKey()])
        ->callFormComponentAction('description.en', 'generateDescription')
        ->assertFormSet([
            'description' => [
                'en' => 'A crisp, reliable ride for city trips.',
                'sq' => 'Një makinë e besueshme për udhëtimet në qytet.',
            ],
        ]);
});

it('applies the suggested daily rate on confirm and hides pricing on create', function () {
    aiOperator('pricing', [PlanFeature::AiPricingSuggestions->value => true]);
    $vehicle = Vehicle::factory()->create(['daily_rate' => 40]);

    PricingSuggestionAgent::fake([['suggested_daily_rate' => 57.5, 'reasoning' => 'Strong recent demand.']]);

    // The AI runs on confirm (not modal mount), sets the rate, and reports the
    // suggested value + reasoning back to the operator.
    Livewire::test(EditVehicle::class, ['record' => $vehicle->getRouteKey()])
        ->callFormComponentAction('daily_rate', 'suggestPrice')
        ->assertFormSet(['daily_rate' => 57.5])
        ->assertNotified();

    // The action is only offered once the vehicle exists — absent on create.
    Livewire::test(CreateVehicle::class)
        ->assertFormComponentActionDoesNotExist('daily_rate', 'suggestPrice');
});

it('suggests a price on a cache store that does not support tagging', function () {
    // The pricing action must work on a non-tagging store (database/file in
    // production). The tenancy CacheManager tag-wraps every facade cache call and
    // such stores throw "does not support tagging"; the array store used elsewhere
    // in tests hides that. (The action no longer caches, but keep this guard so a
    // future reintroduction of a Cache:: call here can't silently break.)
    config(['cache.default' => 'file']);

    aiOperator('pricingcache', [PlanFeature::AiPricingSuggestions->value => true]);
    $vehicle = Vehicle::factory()->create(['daily_rate' => 40]);

    PricingSuggestionAgent::fake([['suggested_daily_rate' => 61.0, 'reasoning' => 'Demand up.']]);

    Livewire::test(EditVehicle::class, ['record' => $vehicle->getRouteKey()])
        ->callFormComponentAction('daily_rate', 'suggestPrice')
        ->assertFormSet(['daily_rate' => 61.0]);
});

it('leaves the rate unchanged and warns when the pricing AI fails', function () {
    aiOperator('pricingfail', [PlanFeature::AiPricingSuggestions->value => true]);
    $vehicle = Vehicle::factory()->create(['daily_rate' => 40]);

    // A throwing agent is wrapped into AiRequestFailedException by the service.
    PricingSuggestionAgent::fake([fn () => throw new RuntimeException('AI down')]);

    Livewire::test(EditVehicle::class, ['record' => $vehicle->getRouteKey()])
        ->callFormComponentAction('daily_rate', 'suggestPrice')
        ->assertNotified()
        ->assertFormSet(['daily_rate' => 40]);
});

it('hides the summary widget without the plan feature', function () {
    aiOperator('nowidget');

    expect(BusinessSummaryWidget::canView())->toBeFalse();
});

it('generates and renders a tenant-scoped summary from the widget', function () {
    [$tenant] = aiOperator('haswidget', [PlanFeature::AiBusinessSummary->value => true]);

    BusinessSummaryAgent::fake([[
        'en' => 'Bookings held steady this week; two returns are overdue.',
        'sq' => 'Rezervimet mbetën të qëndrueshme këtë javë; dy kthime janë vonuar.',
    ]]);

    Livewire::test(BusinessSummaryWidget::class)
        ->call('generate');

    $summary = AiBusinessSummary::query()->first();

    expect($summary)->not->toBeNull()
        ->and($summary->tenant_id)->toBe($tenant->id)
        ->and($summary->contentFor('en'))->toBe('Bookings held steady this week; two returns are overdue.')
        ->and($summary->contentFor('sq'))->toBe('Rezervimet mbetën të qëndrueshme këtë javë; dy kthime janë vonuar.');
});

it('serves the summary in the current locale and falls back to English', function () {
    $summary = new AiBusinessSummary(['content' => ['en' => 'English text.', 'sq' => 'Tekst shqip.']]);

    expect($summary->contentFor('sq'))->toBe('Tekst shqip.')
        ->and($summary->contentFor('en'))->toBe('English text.')
        ->and($summary->contentFor('de'))->toBe('English text.'); // unknown locale → English fallback
});

it('hides the summary widget from staff even with the plan feature', function () {
    aiOperator('staffwidget', [PlanFeature::AiBusinessSummary->value => true], role: 'staff');

    expect(BusinessSummaryWidget::canView())->toBeFalse();
});

it('blocks staff from calling generate directly, not just hiding the widget', function () {
    // Renamed from "bypassing canView": canView() is not bypassable. Filament's
    // widget CanAuthorizeAccess trait re-checks it on every hydration, so the
    // wire call 403s before generate() runs. What this pins is the outcome — a
    // staff member cannot generate a summary by calling the method directly.
    aiOperator('staffgenerate', [PlanFeature::AiBusinessSummary->value => true], role: 'staff');

    BusinessSummaryAgent::fake([['en' => 'Should never be reached.', 'sq' => 'S’duhet arritur kurrë.']]);

    Livewire::test(BusinessSummaryWidget::class)
        ->call('generate');

    expect(AiBusinessSummary::query()->count())->toBe(0);
});

it('blocks an owner without the plan feature from calling generate directly', function () {
    // The outcome that matters: an owner whose plan lacks the feature cannot
    // spend API money by poking generate() over the wire. The staff test above
    // only pins the role half — generate()'s guard is
    // `! isOwner() || ! allowsFeature(...)`, and staff short-circuits on the
    // left operand, so the plan half never evaluates there.
    //
    // Two gates defend this, verified by mutation: Filament\Widgets\Widget uses
    // CanAuthorizeAccess, whose hydrateCanAuthorizeAccess() aborts 403 unless
    // canView() — so the wire call never even reaches generate(). The in-code
    // re-check inside generate() is a redundant backstop for a non-Livewire
    // caller. Breaking either alone leaves this test green; breaking both makes
    // it fail, which is the guarantee worth having.
    aiOperator('ownergenerate', [PlanFeature::AiBusinessSummary->value => false]);

    BusinessSummaryAgent::fake([['en' => 'Should never be reached.', 'sq' => 'S’duhet arritur kurrë.']]);

    Livewire::test(BusinessSummaryWidget::class)
        ->call('generate');

    expect(AiBusinessSummary::query()->count())->toBe(0);
});

it('wraps AI failures in AiRequestFailedException', function () {
    aiOperator('wrapsfail', [PlanFeature::AiBusinessSummary->value => true]);

    // An empty response triggers the malformedResponse path.
    BusinessSummaryAgent::fake([['en' => '', 'sq' => '']]);

    expect(fn () => app(BusinessSummaryGenerator::class)->generate())
        ->toThrow(AiRequestFailedException::class);
});

it('gives asymmetric plan defaults: toggles on, AI off', function () {
    $plan = Plan::factory()->create(['features' => []]);

    expect($plan->allows(PlanFeature::Reports))->toBeTrue()
        ->and($plan->allows(PlanFeature::Branding))->toBeTrue()
        ->and($plan->allows(PlanFeature::PromoCodes))->toBeTrue()
        ->and($plan->allows(PlanFeature::Templates))->toBeTrue()
        ->and($plan->allows(PlanFeature::Reviews))->toBeTrue()
        ->and($plan->allows(PlanFeature::AiListingWriter))->toBeFalse()
        ->and($plan->allows(PlanFeature::AiBusinessSummary))->toBeFalse()
        ->and($plan->allows(PlanFeature::AiPricingSuggestions))->toBeFalse();
});

it('queues summary jobs only for active tenants with the feature enabled', function () {
    Queue::fake();

    Plan::factory()->create(['slug' => 'with-ai', 'features' => [PlanFeature::AiBusinessSummary->value => true]]);
    Plan::factory()->create(['slug' => 'no-ai', 'features' => []]);

    Tenant::factory()->withDomain('enabled')->create(['plan' => 'with-ai']);
    Tenant::factory()->withDomain('disabled')->create(['plan' => 'no-ai']);
    Tenant::factory()->withDomain('suspended')->suspended()->create(['plan' => 'with-ai']);

    $this->artisan('ai:generate-business-summaries')->assertSuccessful();

    Queue::assertPushed(GenerateBusinessSummaryJob::class, 1);
});

it('creates a tenant-scoped summary when the job runs directly', function () {
    [$tenant] = aiOperator('jobruns', [PlanFeature::AiBusinessSummary->value => true]);

    BusinessSummaryAgent::fake([['en' => 'Steady week overall.', 'sq' => 'Javë e qëndrueshme në përgjithësi.']]);

    (new GenerateBusinessSummaryJob($tenant))->handle(app(BusinessSummaryGenerator::class));

    $summary = AiBusinessSummary::query()->first();

    expect($summary)->not->toBeNull()
        ->and($summary->tenant_id)->toBe($tenant->id)
        ->and($summary->contentFor('en'))->toBe('Steady week overall.');
});

it('does not re-check the plan inside the summary job — the command is the only gate', function () {
    [$tenant] = aiOperator('jobnoplan', [PlanFeature::AiBusinessSummary->value => false]);

    BusinessSummaryAgent::fake([['en' => 'Ran anyway.', 'sq' => 'U ekzekutua gjithsesi.']]);

    (new GenerateBusinessSummaryJob($tenant))->handle(app(BusinessSummaryGenerator::class));

    // KNOWN GAP, pinned deliberately: only ai:business-summaries checks the plan
    // before dispatching. A job already queued when a tenant is downgraded still
    // runs — and unlike the maintenance/review jobs, this one spends real AI API
    // money. Narrow (the dispatch window), but the costliest of the three.
    expect(AiBusinessSummary::query()->count())->toBe(1);
});

it('regenerating within the same period updates the existing row instead of duplicating it', function () {
    aiOperator('regenerate', [PlanFeature::AiBusinessSummary->value => true]);

    BusinessSummaryAgent::fake([['en' => 'First pass.', 'sq' => 'Kalimi i parë.']]);
    Livewire::test(BusinessSummaryWidget::class)->call('generate');

    BusinessSummaryAgent::fake([['en' => 'Second pass, same window.', 'sq' => 'Kalimi i dytë, e njëjta dritare.']]);
    Livewire::test(BusinessSummaryWidget::class)->call('generate');

    expect(AiBusinessSummary::query()->count())->toBe(1)
        ->and(AiBusinessSummary::query()->first()->contentFor('en'))->toBe('Second pass, same window.');
});

it('rejects a duplicate summary row for the same tenant and period at the database level', function () {
    aiOperator('dupewindow', [PlanFeature::AiBusinessSummary->value => true]);

    AiBusinessSummary::factory()->create();

    expect(fn () => AiBusinessSummary::factory()->create())
        ->toThrow(UniqueConstraintViolationException::class);
});

it('configures retries and timeout for transient AI failures', function () {
    $job = new GenerateBusinessSummaryJob(Tenant::factory()->make());

    expect($job->tries)->toBe(3)
        ->and($job->timeout)->toBe(60)
        ->and($job->backoff())->toBe([60, 300, 900]);
});

it('logs tenant context when the job fails permanently', function () {
    [$tenant] = aiOperator('jobfailslog', [PlanFeature::AiBusinessSummary->value => true]);

    Log::spy();

    (new GenerateBusinessSummaryJob($tenant))->failed(new AiRequestFailedException('boom'));

    Log::shouldHaveReceived('error')->once()->withArgs(
        fn (string $message, array $context) => $message === 'Business summary generation failed'
            && $context['tenant_id'] === $tenant->id
    );
});
