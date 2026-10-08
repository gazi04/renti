<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\Data\TextUsage;

/**
 * Computes the estimated EUR cost of one AI call from its token usage and the
 * per-model price table in config('ai.pricing'). Providers return token counts
 * only, never a price, so cost is derived app-side. An unknown model (no price
 * row) yields 0 — the usage tokens are still recorded elsewhere.
 */
class AiCostEstimator
{
    /**
     * Models already reported as unpriced, so a busy queue logs once per model
     * per process instead of once per call.
     *
     * @var array<string, true>
     */
    private static array $warnedModels = [];

    public function estimate(string $model, TextUsage $usage): float
    {
        /** @var array{input?: float|int, cached_input?: float|int|null, cache_write_input?: float|int|null, output?: float|int}|null $prices */
        $prices = config('ai.pricing.'.$model);

        if ($prices === null) {
            // €0 is correct while the app runs on the free provider, and wrong
            // the moment a paid model is wired without a price row — at which
            // point every cost figure in the admin panel silently under-reports.
            // Say so rather than letting the zero pass for a real number.
            if (! isset(self::$warnedModels[$model])) {
                self::$warnedModels[$model] = true;

                Log::warning('AI usage recorded with no price row; cost logged as 0.', [
                    'model' => $model,
                    'hint' => "Add a 'pricing.{$model}' entry to config/ai.php once this model is paid.",
                ]);
            }

            return 0.0;
        }

        $input = (float) ($prices['input'] ?? 0);
        $output = (float) ($prices['output'] ?? 0);
        $cachedInput = isset($prices['cached_input']) ? (float) $prices['cached_input'] : $input;
        $cacheWriteInput = isset($prices['cache_write_input']) ? (float) $prices['cache_write_input'] : $input;

        // laravel/ai v1 counts are inclusive: inputTokens already contains the
        // cache-read/-write tokens and outputTokens already contains reasoning,
        // so reasoning must not be added on top of the output count.
        return max(0, $usage->uncachedInputTokens()) / 1_000_000 * $input
            + ($usage->cacheReadInputTokens ?? 0) / 1_000_000 * $cachedInput
            + ($usage->cacheWriteInputTokens ?? 0) / 1_000_000 * $cacheWriteInput
            + $usage->outputTokens / 1_000_000 * $output;
    }
}
