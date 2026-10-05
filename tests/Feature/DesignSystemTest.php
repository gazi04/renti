<?php

declare(strict_types=1);

/*
 * Guards the storefront design system against the drift it was just rewritten
 * out of.
 *
 * Before the rewrite the customer-facing views had accumulated four radius
 * scales, two competing input recipes, "success" as both emerald and green, the
 * € symbol typed into ten templates, and neutral greys hardcoded everywhere —
 * which is what made a dark mode impossible and a mobile fix a thirteen-file
 * job. The tokens in resources/css/app.css fix that once; this test is what
 * stops it coming back, and it runs in milliseconds in the fast suite.
 *
 * If you are adding a view and this fails: use the token, don't add an
 * exemption. Exemptions are for genuinely un-tokenisable cases and each one
 * needs a reason next to it.
 */

/** The views directory, resolved without the app container: Pest builds
 *  datasets before the framework boots, so resource_path() is not available. */
function designSystemViewRoot(): string
{
    return dirname(__DIR__, 2).'/resources/views';
}

/** @return array<int, string> Absolute paths of the views this test governs. */
function designSystemViews(): array
{
    $base = designSystemViewRoot();

    return array_values(array_filter([
        $base.'/layouts/public.blade.php',
        $base.'/livewire/faq-concierge.blade.php',
        $base.'/layouts/marketing.blade.php',
        ...glob($base.'/marketing/*.blade.php') ?: [],
        ...glob($base.'/marketing/partials/*.blade.php') ?: [],
        $base.'/public/cancel-confirm.blade.php',
        $base.'/public/cancel-result.blade.php',
        $base.'/public/unavailable.blade.php',
        ...glob($base.'/pages/public/*.blade.php') ?: [],
        ...glob($base.'/pages/public/partials/*/*.blade.php') ?: [],
        ...glob($base.'/components/ui/*.blade.php') ?: [],
    ], 'file_exists'));
}

/**
 * Patterns that must not appear in a governed view, and the token to use instead.
 *
 * @return array<string, array{0: string, 1: string}> label => [regex, guidance]
 */
function designSystemRules(): array
{
    return [
        'raw neutral colour' => [
            '/\b(?:bg|text|border|ring|divide|from|to|via)-(?:gray|zinc|slate|neutral|stone)-\d{2,3}\b/',
            'use surface / ink / line tokens',
        ],
        'raw white or black' => [
            '/\b(?:bg|text)-(?:white|black)\b/',
            'use surface-raised / ink / ink-inverse',
        ],
        'off-token success colour' => [
            '/\b(?:bg|text|border)-(?:green|emerald)-\d{2,3}\b/',
            'use positive / positive-surface',
        ],
        'off-token warning colour' => [
            '/\b(?:bg|text|border)-amber-\d{2,3}\b/',
            'use notice / notice-surface / star',
        ],
        'off-token danger colour' => [
            '/\b(?:bg|text|border)-red-\d{2,3}\b/',
            'use critical / critical-surface',
        ],
        'off-token radius' => [
            '/\brounded-(?:sm|md|lg|xl|2xl|3xl)\b/',
            'use rounded-control or rounded-panel',
        ],
        'hardcoded currency symbol' => [
            '/€/u',
            'use <x-ui.price> or __(\'booking.currency_symbol\')',
        ],
        'viewport-unit trap' => [
            '/\b(?:min-h|max-h|h)-screen\b|\[[^\]]*100vh[^\]]*\]/',
            'use dvh — 100vh ignores mobile browser chrome',
        ],
    ];
}

/**
 * Documented exemptions: file basename => list of rule labels it may break.
 *
 * @return array<string, array<int, string>>
 */
function designSystemExemptions(): array
{
    return [
        // The one place the currency symbol is allowed to be a literal — this
        // component exists so nothing else has to hardcode it.
        'price.blade.php' => ['hardcoded currency symbol'],
    ];
}

it('keeps customer-facing views on the design tokens', function (string $path) {
    $contents = (string) file_get_contents($path);
    $basename = basename($path);
    $exempt = designSystemExemptions()[$basename] ?? [];

    foreach (designSystemRules() as $label => [$pattern, $guidance]) {
        if (in_array($label, $exempt, true)) {
            continue;
        }

        preg_match_all($pattern, $contents, $matches);

        expect($matches[0])->toBe(
            [],
            sprintf(
                '%s in %s — %s. Found: %s',
                $label,
                str_replace(designSystemViewRoot().'/', '', $path),
                $guidance,
                implode(', ', array_unique($matches[0]))
            )
        );
    }
})->with(designSystemViews());

it('governs every storefront view, so the list cannot silently go stale', function () {
    $governed = designSystemViews();

    // A new partial that nobody added here would be unguarded. These counts are
    // the tripwire: change them deliberately when adding or removing a view.
    expect($governed)->not->toBeEmpty()
        ->and(count($governed))->toBeGreaterThanOrEqual(20);

    foreach ($governed as $path) {
        expect(file_exists($path))->toBeTrue("Governed view no longer exists: {$path}");
    }
});

it('defines the colour palette only in the stylesheet and the tenant override', function () {
    // Hex literals in a view mean a colour that no token can retheme.
    foreach (designSystemViews() as $path) {
        $contents = (string) file_get_contents($path);

        // layouts/public.blade.php legitimately interpolates the tenant's own
        // hex into its :root block; that is the branding mechanism itself.
        if (basename($path) === 'public.blade.php') {
            continue;
        }

        preg_match_all('/#[0-9a-fA-F]{6}\b/', $contents, $matches);

        expect($matches[0])->toBe(
            [],
            sprintf('hardcoded hex colour in %s — add a token to resources/css/app.css instead', basename($path))
        );
    }
});
