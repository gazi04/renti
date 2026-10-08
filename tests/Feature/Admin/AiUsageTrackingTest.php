<?php

use App\Ai\Agents\BusinessSummaryAgent;
use App\Ai\Agents\VehicleListingAgent;
use App\Filament\Resources\AiUsageLogs\Pages\ListAiUsageLogs;
use App\Filament\Widgets\AiUsageStats;
use App\Models\AiUsageLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ai\AiCostEstimator;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

afterEach(function () {
    tenancy()->end();
});

/**
 * A faked structured response carrying a populated Usage/Meta — the default
 * Agent::fake() yields zero usage, so this is how token/cost paths are exercised.
 *
 * @param  array<string, mixed>  $structured
 */
function fakeAiResponse(array $structured, TextUsage $usage, string $provider = 'openai', string $model = 'gpt-5-mini'): StructuredTextResponse
{
    return new StructuredTextResponse($structured, (string) json_encode($structured), $usage, new Meta($provider, $model));
}

it('records one usage row with mapped tokens and central attribution', function () {
    // TextUsage(input, output, cacheRead, cacheWrite, reasoning)
    VehicleListingAgent::fake([fakeAiResponse(['description' => 'A ride.'], new TextUsage(1000, 500, 200, 0, 0))]);

    (new VehicleListingAgent)->prompt('facts');

    $row = AiUsageLog::query()->sole();

    expect($row->feature)->toBe('listing')
        ->and($row->provider)->toBe('openai')
        ->and($row->model)->toBe('gpt-5-mini')
        ->and($row->prompt_tokens)->toBe(1000)
        ->and($row->completion_tokens)->toBe(500)
        ->and($row->cache_read_input_tokens)->toBe(200)
        ->and($row->cache_write_input_tokens)->toBe(0)
        ->and($row->reasoning_tokens)->toBe(0)
        ->and($row->total_tokens)->toBe(1500)
        ->and($row->tenant_id)->toBeNull();
});

it('computes estimated cost from the config price table', function () {
    config(['ai.pricing.gpt-5-mini' => ['input' => 10, 'cached_input' => 5, 'output' => 30]]);

    VehicleListingAgent::fake([fakeAiResponse(['description' => 'A ride.'], new TextUsage(1000, 500, 200, 0, 0))]);

    (new VehicleListingAgent)->prompt('facts');

    // non_cached = 800 → 800/1e6*10 + 200/1e6*5 + 500/1e6*30 = 0.008 + 0.001 + 0.015
    expect((float) AiUsageLog::query()->sole()->estimated_cost)->toBe(0.024);
});

it('bills reasoning once, as part of the output count', function () {
    config(['ai.pricing.gpt-5-mini' => ['input' => 10, 'output' => 30]]);

    // laravel/ai v1: the 100 reasoning tokens are already inside the 500 output.
    VehicleListingAgent::fake([fakeAiResponse(['description' => 'A ride.'], new TextUsage(1000, 500, null, null, 100))]);

    (new VehicleListingAgent)->prompt('facts');

    $row = AiUsageLog::query()->sole();

    // 1000/1e6*10 + 500/1e6*30 = 0.01 + 0.015
    expect((float) $row->estimated_cost)->toBe(0.025)
        ->and($row->reasoning_tokens)->toBe(100)
        ->and($row->total_tokens)->toBe(1500);
});

it('records zeros when the provider omits the optional token counts', function () {
    VehicleListingAgent::fake([fakeAiResponse(['description' => 'A ride.'], new TextUsage(300, 120))]);

    (new VehicleListingAgent)->prompt('facts');

    $row = AiUsageLog::query()->sole();

    expect($row->prompt_tokens)->toBe(300)
        ->and($row->completion_tokens)->toBe(120)
        ->and($row->cache_read_input_tokens)->toBe(0)
        ->and($row->cache_write_input_tokens)->toBe(0)
        ->and($row->reasoning_tokens)->toBe(0);
});

it('estimates zero when no price row exists (free-provider beta reality)', function () {
    // Plain fake → zero Usage, and the running model has no pricing row.
    VehicleListingAgent::fake([['description' => 'A ride.']]);

    (new VehicleListingAgent)->prompt('facts');

    $row = AiUsageLog::query()->sole();

    expect($row->total_tokens)->toBe(0)
        ->and((float) $row->estimated_cost)->toBe(0.0);
});

it('warns once per model when a call is priced at zero for lack of a price row', function () {
    Log::spy();

    $estimator = new AiCostEstimator;
    $usage = new TextUsage(1000, 500, 0, 0, 0);

    expect($estimator->estimate('some-paid-model', $usage))->toBe(0.0)
        ->and($estimator->estimate('some-paid-model', $usage))->toBe(0.0);

    // Twice called, logged once — a busy queue must not flood the log.
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $context['model'] === 'some-paid-model');
});

it('stays silent when the model has a price row', function () {
    config(['ai.pricing.priced-model' => ['input' => 10, 'output' => 30]]);
    Log::spy();

    (new AiCostEstimator)->estimate('priced-model', new TextUsage(1000, 500, 0, 0, 0));

    Log::shouldNotHaveReceived('warning');
});

it('attributes the row to the active tenant', function () {
    $tenant = Tenant::factory()->create();
    tenancy()->initialize($tenant);

    BusinessSummaryAgent::fake([fakeAiResponse(['en' => 'x', 'sq' => 'y'], new TextUsage(100, 50, 0, 0, 0))]);

    (new BusinessSummaryAgent)->prompt('metrics');

    expect(AiUsageLog::query()->sole()->tenant_id)->toBe($tenant->id);
});

it('never breaks the AI call when recording fails', function () {
    $this->mock(AiCostEstimator::class)
        ->shouldReceive('estimate')
        ->andThrow(new RuntimeException('boom'));

    VehicleListingAgent::fake([fakeAiResponse(['description' => 'A ride.'], new TextUsage(1000, 500, 0, 0, 0))]);

    $response = (new VehicleListingAgent)->prompt('facts');

    expect($response->toArray()['description'])->toBe('A ride.')
        ->and(AiUsageLog::query()->count())->toBe(0);
});

it('renders the read-only usage resource for a super admin', function () {
    $rows = AiUsageLog::factory()->count(3)->create();

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    actingAs(User::factory()->admin()->create());

    Livewire::test(ListAiUsageLogs::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords($rows);
});

it('summarizes token totals so the admin can see per-tenant usage', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    AiUsageLog::factory()->create(['tenant_id' => $tenantA->id, 'total_tokens' => 400]);
    AiUsageLog::factory()->create(['tenant_id' => $tenantA->id, 'total_tokens' => 600]);
    AiUsageLog::factory()->create(['tenant_id' => $tenantB->id, 'total_tokens' => 100]);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    actingAs(User::factory()->admin()->create());

    // Ungrouped: the Sum summarizer shows a grand total (1,100) in the footer.
    // Grouped by tenant: each group summarizes to its own total — 1,000 and 100.
    Livewire::test(ListAiUsageLogs::class)
        ->assertSuccessful()
        ->assertSee('1,100')
        ->set('tableGrouping', 'tenant_id')
        ->assertSee('1,000')
        ->assertSee('100');
});

it('shows platform-wide usage on the admin stats widget', function () {
    AiUsageLog::factory()->count(3)->create(['created_at' => now(), 'total_tokens' => 400]);
    // Last month — excluded from the monthly stats.
    AiUsageLog::factory()->create(['created_at' => now()->subMonthNoOverflow()->startOfMonth(), 'total_tokens' => 999]);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    actingAs(User::factory()->admin()->create());

    Livewire::test(AiUsageStats::class)
        ->assertSee('AI calls this month')
        ->assertSee('3')
        ->assertSee('1,200'); // 3 × 400 tokens this month
});
