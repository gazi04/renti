<?php

declare(strict_types=1);

/*
 * Free-text content the operator writes, stored per language as
 * {key}_sq / {key}_en (the base un-suffixed key is kept on the allow-list
 * for values saved before the bilingual split — see Tenant::localizedSetting()).
 */
$localizedContentKeys = [
    'footer_text',
    'home_hero_heading',
    'home_hero_subheading',
    'home_hero_cta_label',
    'home_about_title',
    'home_about_text',
    'home_service_1_title',
    'home_service_1_text',
    'home_service_2_title',
    'home_service_2_text',
    'home_service_3_title',
    'home_service_3_text',
];

$localizedVariants = [];
foreach ($localizedContentKeys as $key) {
    $localizedVariants[] = $key.'_sq';
    $localizedVariants[] = $key.'_en';
}

return [
    /*
     * Keys operators may write via setSetting(). Any key NOT in this list
     * is silently rejected — prevents mass-assignment of arbitrary settings.
     */
    'keys' => [
        ...$localizedContentKeys,
        ...$localizedVariants,
        'color_primary',
        'color_secondary',
        'font_family',
        'social_facebook',
        'social_instagram',
        'contact_phone',
        'contact_email',
        'contact_address',
        'payment_instructions',
        'default_locale',
    ],

    /*
     * Content keys that exist per language (used by the branding form to
     * render one field set per locale).
     */
    'localized_keys' => $localizedContentKeys,

    /*
     * Curated font allow-list.  The key is the canonical name stored in
     * tenant_settings; never allow free-text fonts (injection + layout risk).
     *
     * 'alias' is the Vite font-manifest slug — these faces are self-hosted
     * (see the `fonts` array in vite.config.js), so nothing is fetched from a
     * third party at runtime.  The alias is passed to @fonts() in
     * layouts/public.blade.php, which THROWS if it is not in the built
     * manifest — so this list and vite.config.js must stay in step.  That is
     * asserted by the 'has a built font-manifest entry for every curated font'
     * test in tests/Feature/BrandingTest.php.
     */
    'fonts' => [
        'Inter' => ['label' => 'Inter', 'alias' => 'inter'],
        'Poppins' => ['label' => 'Poppins', 'alias' => 'poppins'],
        'Roboto' => ['label' => 'Roboto', 'alias' => 'roboto'],
        'Nunito' => ['label' => 'Nunito', 'alias' => 'nunito'],
        'Lato' => ['label' => 'Lato', 'alias' => 'lato'],
    ],

    /*
     * Single source of truth for the accepted hex-color format. Referenced
     * by both the Filament form rule (BrandingSettings.php) and the
     * model-layer guard (Tenant::setSetting()) so the two can't drift out
     * of sync.
     */
    'color_format' => '/^#[0-9a-fA-F]{6}$/',

    'defaults' => [
        'color_primary' => '#2e4bff',
        'color_secondary' => '#1b32d8',
        'font_family' => 'Poppins',
        'default_locale' => 'sq',
    ],
];
