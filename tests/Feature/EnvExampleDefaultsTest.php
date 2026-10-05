<?php

use Stancl\Tenancy\Bootstrappers\RedisTenancyBootstrapper;

/**
 * @return array<string, string>
 */
function envExampleValues(): array
{
    $values = [];

    foreach (file(base_path('.env.example'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || ! str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $values[trim($key)] = trim($value);
    }

    return $values;
}

it('ships redis as the cache, session and queue default', function (string $key): void {
    expect(envExampleValues())->toHaveKey($key, 'redis');
})->with(['CACHE_STORE', 'SESSION_DRIVER', 'QUEUE_CONNECTION']);

it('keeps redis tenancy off so sessions and queues are shared across tenants', function (): void {
    expect(config('tenancy.bootstrappers'))->not->toContain(RedisTenancyBootstrapper::class);
});
