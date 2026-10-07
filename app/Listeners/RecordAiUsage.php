<?php

namespace App\Listeners;

use App\Ai\Contracts\ReportsAiUsage;
use App\Models\AiUsageLog;
use App\Models\Tenant;
use App\Services\Ai\AiCostEstimator;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Throwable;

/**
 * Records one AiUsageLog row per completed AI prompt. Listens on AgentPrompted,
 * which laravel/ai dispatches at the end of every ->prompt() call, so all three
 * AI features are captured without touching their service classes. Only agents
 * that opt in via ReportsAiUsage are logged.
 *
 * The event dispatches synchronously inside prompt(), so any error here would
 * bubble into a live operator-facing AI feature — the whole body is therefore
 * best-effort and swallows failures via report().
 *
 * Registered automatically via Laravel's listener discovery (the handle()
 * type-hint) — do NOT also Event::listen() it, or every call is logged twice.
 *
 * Since laravel/ai v1 (2026-10) prompt_tokens/completion_tokens hold the
 * provider's inclusive counts (cached tokens inside input, reasoning inside
 * output); older rows excluded them. The optional counts are null when the
 * provider doesn't report them, and the columns are NOT NULL, hence ?? 0.
 */
class RecordAiUsage
{
    public function __construct(private readonly AiCostEstimator $estimator) {}

    public function handle(AgentPrompted $event): void
    {
        try {
            $agent = $event->prompt->agent;
            $response = $event->response;

            // Streamed responses only finalize their usage after the stream is
            // consumed; the AI features here never stream, so skip them defensively.
            if (! $agent instanceof ReportsAiUsage || $response instanceof StreamedAgentResponse) {
                return;
            }

            $usage = $response->usage;
            $model = $response->meta->model ?? $event->prompt->model;

            AiUsageLog::query()->create([
                'tenant_id' => Tenant::current()?->id,
                'feature' => $agent->aiFeature(),
                'provider' => $response->meta->provider ?? '',
                'model' => $model,
                'prompt_tokens' => $usage->inputTokens,
                'completion_tokens' => $usage->outputTokens,
                'reasoning_tokens' => $usage->reasoningTokens ?? 0,
                'cache_read_input_tokens' => $usage->cacheReadInputTokens ?? 0,
                'cache_write_input_tokens' => $usage->cacheWriteInputTokens ?? 0,
                'total_tokens' => $usage->totalTokens(),
                'estimated_cost' => $this->estimator->estimate($model, $usage),
                'created_at' => now(),
            ]);
        } catch (Throwable $throwable) {
            report($throwable);
        }
    }
}
