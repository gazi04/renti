<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider Names
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the AI providers below should be the
    | default for AI operations when no explicit provider is provided
    | for the operation. This should be any provider defined below.
    |
    */

    'default' => 'openai',
    'default_for_images' => 'gemini',
    'default_for_audio' => 'openai',
    'default_for_transcription' => 'openai',
    'default_for_embeddings' => 'openai',
    'default_for_reranking' => 'cohere',

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    |
    | Below you may configure caching strategies for AI related operations
    | such as embedding generation. You are free to adjust these values
    | based on your application's available caching stores and needs.
    |
    */

    'caching' => [
        'embeddings' => [
            'cache' => false,
            'store' => env('CACHE_STORE', 'database'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Providers
    |--------------------------------------------------------------------------
    |
    | Below are each of your AI providers defined for this application. Each
    | represents an AI provider and API key combination which can be used
    | to perform tasks like text, image, and audio creation via agents.
    |
    */

    'providers' => [
        // GitHub Models — OpenAI-compatible endpoint. Auth is a GitHub token
        // (PAT / GITHUB_TOKEN) sent as a Bearer token. Model names are prefixed,
        // e.g. "openai/gpt-4.1". Free tier has low rate limits (dev/testing).
        'github' => [
            'driver' => 'openai-compatible',
            'url' => env('GITHUB_MODELS_URL', 'https://models.github.ai/inference'),
            'key' => env('GITHUB_TOKEN'),
            'models' => [
                'text' => ['default' => env('GITHUB_MODELS_MODEL', 'openai/gpt-4.1')],
            ],
        ],

        'anthropic' => [
            'driver' => 'anthropic',
            'key' => env('ANTHROPIC_API_KEY'),
            'url' => env('ANTHROPIC_URL', 'https://api.anthropic.com/v1'),
        ],

        'azure' => [
            'driver' => 'azure',
            'key' => env('AZURE_OPENAI_API_KEY'),
            'url' => env('AZURE_OPENAI_URL'),
            'api_version' => env('AZURE_OPENAI_API_VERSION', '2025-04-01-preview'),
            'deployment' => env('AZURE_OPENAI_DEPLOYMENT', 'gpt-4o'),
            'embedding_deployment' => env('AZURE_OPENAI_EMBEDDING_DEPLOYMENT', 'text-embedding-3-small'),
            'image_deployment' => env('AZURE_OPENAI_IMAGE_DEPLOYMENT', 'gpt-image-1'),
            'store' => env('AZURE_OPENAI_STORE', true),
        ],

        'bedrock' => [
            'driver' => 'bedrock',
            'region' => env('AWS_BEDROCK_REGION', 'us-east-1'),
            'key' => env('AWS_BEARER_TOKEN_BEDROCK'),
            'access_key_id' => env('AWS_ACCESS_KEY_ID'),
            'secret_access_key' => env('AWS_SECRET_ACCESS_KEY'),
            'session_token' => env('AWS_SESSION_TOKEN'),
            'use_default_credential_provider' => env('AWS_USE_DEFAULT_CREDENTIALS', true),
        ],

        'cohere' => [
            'driver' => 'cohere',
            'key' => env('COHERE_API_KEY'),
        ],

        'deepseek' => [
            'driver' => 'deepseek',
            'key' => env('DEEPSEEK_API_KEY'),
        ],

        'eleven' => [
            'driver' => 'eleven',
            'key' => env('ELEVENLABS_API_KEY'),
        ],

        'gemini' => [
            'driver' => 'gemini',
            'key' => env('GEMINI_API_KEY'),
            'url' => env('GEMINI_URL', 'https://generativelanguage.googleapis.com/v1beta/'),
        ],

        'groq' => [
            'driver' => 'groq',
            'key' => env('GROQ_API_KEY'),
        ],

        'jina' => [
            'driver' => 'jina',
            'key' => env('JINA_API_KEY'),
        ],

        'mistral' => [
            'driver' => 'mistral',
            'key' => env('MISTRAL_API_KEY'),
        ],

        'ollama' => [
            'driver' => 'ollama',
            'key' => env('OLLAMA_API_KEY', ''),
            'url' => env('OLLAMA_URL', 'http://localhost:11434'),
        ],

        'openai' => [
            'driver' => 'openai',
            'key' => env('OPENAI_API_KEY'),
            'url' => env('OPENAI_URL', 'https://api.openai.com/v1'),
            'store' => env('OPENAI_STORE', true),

            // The default text model used by every AI operator feature. Kept as
            // a single knob (OPENAI_MODEL) so the model can be changed without
            // touching the agent classes; see App\Ai\Agents\*.
            'models' => [
                'text' => [
                    'default' => env('OPENAI_MODEL', 'gpt-5-mini'),
                ],
            ],
        ],

        'openai-compatible' => [
            'driver' => 'openai-compatible',
            'url' => env('OPENAI_COMPATIBLE_URL'),
            'key' => env('OPENAI_COMPATIBLE_API_KEY'),
        ],

        'openrouter' => [
            'driver' => 'openrouter',
            'key' => env('OPENROUTER_API_KEY'),
        ],

        'voyageai' => [
            'driver' => 'voyageai',
            'key' => env('VOYAGEAI_API_KEY'),
        ],

        'xai' => [
            'driver' => 'xai',
            'key' => env('XAI_API_KEY'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Operator AI feature knobs
    |--------------------------------------------------------------------------
    |
    | Application-specific settings for the operator AI features. Kept under the
    | "ai." namespace so existing config('ai.*') reads keep resolving after the
    | migration to the Laravel AI SDK.
    |
    */

    /** Max vehicle photos attached to a listing-writer request (image-token cap). */
    'max_photos' => 3,

    /** How many days of data the weekly business summary covers. */
    'summary_period_days' => 7,

    /**
     * Max FAQ-concierge questions one tenant may answer per rolling 24h. The
     * brake a per-IP limit cannot be against rotating IPs — on cap the visitor
     * gets the fallback line, never an error. (Anonymous storefront traffic is
     * the only AI feature with no manual-click ceiling.)
     */
    'concierge_daily_cap' => 200,

    /*
    |--------------------------------------------------------------------------
    | AI usage pricing (estimated € per 1,000,000 tokens, per model)
    |--------------------------------------------------------------------------
    |
    | Cost is computed app-side (providers return token counts only, never a
    | price) by AiCostEstimator using this formula:
    |
    |   non_cached_input = prompt_tokens − cache_read − cache_write
    |   cost = non_cached_input           / 1e6 * input
    |        + cache_read_input_tokens     / 1e6 * (cached_input ?? input)
    |        + cache_write_input_tokens    / 1e6 * (cache_write_input ?? input)
    |        + completion_tokens           / 1e6 * output   (already includes reasoning)
    |
    | Beta runs on GitHub Models (openai/gpt-4.1, free) — no row here, so cost
    | resolves to 0 while usage tokens are still recorded. Add a row (keyed by
    | the exact model string the agent reports) when a paid model is wired; no
    | code change is needed. Prices are in EUR — verify against live provider
    | pricing and apply the current USD→EUR rate before relying on them.
    |
    */
    'pricing' => [
        // 'gpt-5-mini' => ['input' => 0.00, 'cached_input' => 0.00, 'output' => 0.00],
    ],

];
