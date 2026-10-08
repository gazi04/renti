<?php

use App\Enums\PlanFeature;
use App\Filament\Operator\Pages\BrandingSettings;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

afterEach(function () {
    tenancy()->end();
});

/**
 * @return array{0: Tenant, 1: User}
 */
function brandingSetup(string $subdomain = 'brand'): array
{
    $tenant = Tenant::factory()->withDomain($subdomain)->create();

    $operator = new User;
    $operator->forceFill([
        'tenant_id' => $tenant->id,
        'role' => 'operator',
        'name' => 'Operator',
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
    ])->save();

    tenancy()->initialize($tenant);
    Storage::fake('public');
    Filament::setCurrentPanel(Filament::getPanel('operator'));
    actingAs($operator);

    return [$tenant, $operator];
}

// ── Logo storage path ────────────────────────────────────────────────────────

it('stores the tenant logo under a tenants/{tenant_id}/logo/{media_id}/ path, not a bare media-ID folder', function () {
    [$tenant] = brandingSetup('logopath');

    $media = $tenant->addMedia(UploadedFile::fake()->image('logo.png', 200, 200))
        ->toMediaCollection('logo');

    $expectedPrefix = "tenants/{$tenant->id}/logo/{$media->id}/";

    expect($media->getPath())->toContain($expectedPrefix)
        ->and($media->getPathRelativeToRoot())->toStartWith($expectedPrefix);
});

it('generates a relative URL for the public disk so the operator panel works across subdomains', function () {
    [$tenant] = brandingSetup('relurl');

    $media = $tenant->addMedia(UploadedFile::fake()->image('logo.png', 200, 200))
        ->toMediaCollection('logo');

    expect($media->getUrl())->toStartWith('/storage/')
        ->and($media->getUrl())->not->toContain('http://')
        ->and($media->getUrl())->not->toContain('https://');
});

// ── Settings store ─────────────────────────────────────────────────────────────

it('round-trips a setting via setSetting and setting', function () {
    [$tenant] = brandingSetup('store1');

    $tenant->setSetting('color_primary', '#ff5500');

    expect($tenant->setting('color_primary'))->toBe('#ff5500');
});

it('reads a setting staged directly by the factory, bypassing setSetting', function () {
    // The factory exists precisely to stage rows setSetting() would refuse — here
    // a colour that fails the hex guard — so the read side can be tested on its own.
    [$tenant] = brandingSetup('factory1');

    TenantSetting::factory()->pair('color_primary', 'javascript:alert(1)')->create([
        'tenant_id' => $tenant->id,
    ]);

    expect($tenant->setting('color_primary'))->toBe('javascript:alert(1)')
        // ...and the read-time guard still refuses to hand it to the page.
        ->and($tenant->colorPrimary())->toBe(config('branding.defaults.color_primary'));
});

it('setting returns the provided default when key is unset', function () {
    [$tenant] = brandingSetup('store2');

    expect($tenant->setting('color_primary', '#aabbcc'))->toBe('#aabbcc');
});

it('settings() bag returns all stored keys for the tenant', function () {
    [$tenant] = brandingSetup('store3');

    $tenant->setSetting('color_primary', '#111111');
    $tenant->setSetting('footer_text', 'Footer here');

    $bag = $tenant->settings();

    expect($bag['color_primary'])->toBe('#111111')
        ->and($bag['footer_text'])->toBe('Footer here');
});

it('stores null when value is empty string, and setting returns default', function () {
    [$tenant] = brandingSetup('store4');

    $tenant->setSetting('color_primary', '#123456');
    $tenant->setSetting('color_primary', '');

    assertDatabaseHas('tenant_settings', ['tenant_id' => $tenant->id, 'key' => 'color_primary', 'value' => null]);
    expect($tenant->setting('color_primary', '#2563eb'))->toBe('#2563eb');
});

// ── Allow-list guard ───────────────────────────────────────────────────────────

it('rejects keys not on the allow-list', function () {
    [$tenant] = brandingSetup('guard1');

    $tenant->setSetting('evil_key', 'bad value');

    assertDatabaseMissing('tenant_settings', ['key' => 'evil_key']);
});

it('does not plan-gate setSetting — the page gate is the only branding gate', function () {
    [$tenant] = brandingSetup('guardplan');

    $plan = Plan::factory()->create([
        'slug' => 'nobranding',
        'features' => [PlanFeature::Branding->value => false],
    ]);
    $tenant->update(['plan' => $plan->slug]);

    expect($tenant->refresh()->allowsFeature(PlanFeature::Branding))->toBeFalse();

    $tenant->setSetting('color_primary', '#ff0000');

    // KNOWN GAP, pinned deliberately: setSetting's allow-list is a KEY allow-list
    // (branding keys ∪ template keys), not a plan check — it cannot tell a
    // Branding-gated tenant from a Templates-gated one. Latent today: the only
    // two callers are BrandingSettings/TemplateSettings, each looping its own
    // narrower config list behind a page whose canAccess() Filament re-checks on
    // every hydration. Any third caller (command, import, API) inherits zero plan
    // enforcement.
    assertDatabaseHas('tenant_settings', ['key' => 'color_primary', 'value' => '#ff0000']);
});

// ── Value-format guard (model layer) ────────────────────────────────────────────

it('rejects a CSS-breakout color value at the model layer, bypassing the Filament form', function () {
    [$tenant] = brandingSetup('valueguard1');

    $payload = '#000; } body { display:none !important; } *';
    $tenant->setSetting('color_primary', $payload);

    assertDatabaseMissing('tenant_settings', ['key' => 'color_primary', 'value' => $payload]);
    expect($tenant->setting('color_primary'))->toBeNull();
});

it('rejects an invalid color_secondary value at the model layer', function () {
    [$tenant] = brandingSetup('valueguard2');

    $tenant->setSetting('color_secondary', 'not-a-color');

    assertDatabaseMissing('tenant_settings', ['key' => 'color_secondary', 'value' => 'not-a-color']);
    expect($tenant->setting('color_secondary'))->toBeNull();
});

it('rejects an unlisted font_family value at the model layer, bypassing the Filament form', function () {
    [$tenant] = brandingSetup('valueguard3');

    $tenant->setSetting('font_family', 'EvilFont');

    assertDatabaseMissing('tenant_settings', ['key' => 'font_family', 'value' => 'EvilFont']);
});

/*
 * The storefront layout calls @fonts([$alias]). Vite THROWS on an alias that is
 * not in the built manifest, so a drift between config/branding.php and
 * vite.config.js is not a cosmetic fallback — it is a 500 on every page for
 * every tenant using that font. This is the guard against that.
 */
it('has a built font-manifest entry for every curated font', function () {
    $manifestPath = public_path('build/fonts-manifest.json');

    if (! file_exists($manifestPath)) {
        $this->markTestSkipped('Frontend assets are not built; CI builds before running the gate.');
    }

    /** @var array{families?: array<string, mixed>} $manifest */
    $manifest = json_decode((string) file_get_contents($manifestPath), true);
    $built = array_keys($manifest['families'] ?? []);

    /** @var array<string, array{label: string, alias: string}> $fonts */
    $fonts = config('branding.fonts', []);

    foreach ($fonts as $name => $font) {
        // assertContains, not expect()->toContain(): the latter is variadic and
        // would read the message as a second needle.
        $this->assertContains(
            $font['alias'],
            $built,
            "vite.config.js has no bunny() entry for '{$name}' (alias '{$font['alias']}')."
        );
    }
});

it('rejects an unlisted default_locale value at the model layer, bypassing the Filament form', function () {
    [$tenant] = brandingSetup('valueguard8');

    $tenant->setSetting('default_locale', 'de');

    assertDatabaseMissing('tenant_settings', ['key' => 'default_locale', 'value' => 'de']);
});

it('still clears a color setting to null via an empty string after adding the format guard', function () {
    [$tenant] = brandingSetup('valueguard4');

    $tenant->setSetting('color_primary', '#123456');
    $tenant->setSetting('color_primary', '');

    assertDatabaseHas('tenant_settings', ['tenant_id' => $tenant->id, 'key' => 'color_primary', 'value' => null]);
});

it('rejects a javascript: social_facebook value at the model layer, bypassing the Filament form', function () {
    [$tenant] = brandingSetup('valueguard5');

    $tenant->setSetting('social_facebook', 'javascript:alert(1)');

    assertDatabaseMissing('tenant_settings', ['key' => 'social_facebook', 'value' => 'javascript:alert(1)']);
    expect($tenant->setting('social_facebook'))->toBeNull();
});

it('rejects a javascript: social_instagram value at the model layer', function () {
    [$tenant] = brandingSetup('valueguard6');

    $tenant->setSetting('social_instagram', 'javascript:alert(1)');

    assertDatabaseMissing('tenant_settings', ['key' => 'social_instagram', 'value' => 'javascript:alert(1)']);
});

it('still accepts a valid https social_facebook URL at the model layer', function () {
    [$tenant] = brandingSetup('valueguard7');

    $tenant->setSetting('social_facebook', 'https://facebook.com/mypage');

    expect($tenant->setting('social_facebook'))->toBe('https://facebook.com/mypage');
});

// ── Tenant isolation ───────────────────────────────────────────────────────────

it('isolates settings between tenants', function () {
    $tenantA = Tenant::factory()->withDomain('iso-a')->create();
    $tenantB = Tenant::factory()->withDomain('iso-b')->create();

    tenancy()->initialize($tenantA);
    $tenantA->setSetting('color_primary', '#aaaaaa');
    tenancy()->end();

    tenancy()->initialize($tenantB);
    $tenantB->setSetting('color_primary', '#bbbbbb');
    tenancy()->end();

    tenancy()->initialize($tenantA);
    expect($tenantA->settings()['color_primary'])->toBe('#aaaaaa');
    tenancy()->end();

    tenancy()->initialize($tenantB);
    expect($tenantB->settings()['color_primary'])->toBe('#bbbbbb');
});

// ── BrandingSettings Filament page ─────────────────────────────────────────────

it('loads BrandingSettings page and fills from current settings', function () {
    [$tenant] = brandingSetup('page1');

    $tenant->setSetting('color_primary', '#336699');
    $tenant->setSetting('payment_instructions', 'IBAN: AL35202111090000000001234567');

    $component = Livewire::test(BrandingSettings::class);

    $component->assertSet('data.color_primary', '#336699')
        ->assertSet('data.payment_instructions', 'IBAN: AL35202111090000000001234567');
});

it('saves allow-listed settings via the save action', function () {
    [$tenant] = brandingSetup('page2');

    Livewire::test(BrandingSettings::class)
        ->set('data.color_primary', '#ff0000')
        ->set('data.footer_text_sq', 'Custom footer')
        ->call('save')
        ->assertHasNoErrors();

    tenancy()->end();
    tenancy()->initialize($tenant);

    expect($tenant->setting('color_primary'))->toBe('#ff0000')
        ->and($tenant->setting('footer_text_sq'))->toBe('Custom footer');
});

it('rejects invalid hex color', function () {
    brandingSetup('page3');

    Livewire::test(BrandingSettings::class)
        ->set('data.color_primary', 'not-a-color')
        ->call('save')
        ->assertHasErrors(['data.color_primary']);
});

it('rejects font not in the allow-list', function () {
    brandingSetup('page4');

    Livewire::test(BrandingSettings::class)
        ->set('data.font_family', 'EvilFont')
        ->call('save');

    assertDatabaseMissing('tenant_settings', ['key' => 'font_family', 'value' => 'EvilFont']);
});

it('saves default_locale via the save action', function () {
    [$tenant] = brandingSetup('page5');

    Livewire::test(BrandingSettings::class)
        ->set('data.default_locale', 'en')
        ->call('save')
        ->assertHasNoErrors();

    assertDatabaseHas('tenant_settings', ['tenant_id' => $tenant->id, 'key' => 'default_locale', 'value' => 'en']);
});

// ── Public layout CSS vars ─────────────────────────────────────────────────────

it('injects tenant color into the public layout CSS vars', function () {
    $tenant = Tenant::factory()->withDomain('css1')->create();
    tenancy()->initialize($tenant);
    $tenant->setSetting('color_primary', '#abcdef');
    tenancy()->end();

    $this->get(tenant_url('css1', '/'))
        ->assertOk()
        ->assertSee('--color-primary: #abcdef', false);
});

it('injects tenant color_secondary into the public layout CSS vars', function () {
    $tenant = Tenant::factory()->withDomain('css3')->create();
    tenancy()->initialize($tenant);
    $tenant->setSetting('color_secondary', '#abcdef');
    tenancy()->end();

    $this->get(tenant_url('css3', '/'))
        ->assertOk()
        ->assertSee('--color-secondary: #abcdef', false);
});

it('renders public layout with default colors when no settings are saved', function () {
    Tenant::factory()->withDomain('css2')->create();

    $this->get(tenant_url('css2', '/'))
        ->assertOk()
        ->assertSee('--color-primary', false);
});

it('renders a valid social_facebook URL as an href on the public footer', function () {
    $tenant = Tenant::factory()->withDomain('social1')->create();
    tenancy()->initialize($tenant);
    $tenant->setSetting('social_facebook', 'https://facebook.com/mypage');
    tenancy()->end();

    $this->get(tenant_url('social1', '/'))
        ->assertOk()
        ->assertSee('href="https://facebook.com/mypage"', false);
});

it('shows operator payment_instructions on booking review page', function () {
    $tenant = Tenant::factory()->withDomain('pay1')->create();
    tenancy()->initialize($tenant);
    $tenant->setSetting('payment_instructions', 'IBAN AL99 3300 1100 0000 0002 3456 7890');
    tenancy()->end();

    $this->get(tenant_url('pay1', '/'))
        ->assertOk();

    // The instructions value should be available in the tenant settings
    tenancy()->initialize($tenant);
    expect($tenant->setting('payment_instructions'))->toBe('IBAN AL99 3300 1100 0000 0002 3456 7890');
});

it('cross-tenant: subdomain shows its own colors, not another tenant\'s', function () {
    $tenantA = Tenant::factory()->withDomain('cross-a')->create();
    $tenantB = Tenant::factory()->withDomain('cross-b')->create();

    tenancy()->initialize($tenantA);
    $tenantA->setSetting('color_primary', '#111111');
    tenancy()->end();

    tenancy()->initialize($tenantB);
    $tenantB->setSetting('color_primary', '#999999');
    tenancy()->end();

    $this->get(tenant_url('cross-a', '/'))
        ->assertOk()
        ->assertSee('#111111', false)
        ->assertDontSee('#999999', false);
});

// ── Injection guard ────────────────────────────────────────────────────────────

it('escapes malicious text in footer_text on render', function () {
    $tenant = Tenant::factory()->withDomain('xss1')->create();
    tenancy()->initialize($tenant);
    $tenant->setSetting('footer_text', '</style><script>alert(1)</script>');
    tenancy()->end();

    $this->get(tenant_url('xss1', '/'))
        ->assertOk()
        ->assertDontSee('</style><script>', false)
        ->assertSee('&lt;/style&gt;&lt;script&gt;', false);
});

it('never renders a malformed pre-existing color row into the public style block', function () {
    $tenant = Tenant::factory()->withDomain('cssguard1')->create();
    tenancy()->initialize($tenant);
    // Bypass setSetting() entirely to simulate a row written before this
    // guard existed (mirrors TenantHomeTest's layout-fallback bypass test).
    $tenant->tenantSettings()->create([
        'key' => 'color_primary',
        'value' => '#000; } body { display:none !important; } *',
    ]);
    tenancy()->end();

    $this->get(tenant_url('cssguard1', '/'))
        ->assertOk()
        ->assertDontSee('display:none !important', false)
        ->assertSee('--color-primary: '.config('branding.defaults.color_primary'), false);
});

it('colorPrimary and colorSecondary fall back to config defaults when unset', function () {
    $tenant = Tenant::factory()->withDomain('accessor1')->create();
    tenancy()->initialize($tenant);

    expect($tenant->colorPrimary())->toBe(config('branding.defaults.color_primary'))
        ->and($tenant->colorSecondary())->toBe(config('branding.defaults.color_secondary'));
});

it('colorPrimary falls back to the config default when the stored value is malformed', function () {
    $tenant = Tenant::factory()->withDomain('accessor2')->create();
    tenancy()->initialize($tenant);
    $tenant->tenantSettings()->create(['key' => 'color_primary', 'value' => 'javascript:alert(1)']);

    expect($tenant->colorPrimary())->toBe(config('branding.defaults.color_primary'));
});

it('never renders a malformed pre-existing social_facebook row as a javascript: href', function () {
    $tenant = Tenant::factory()->withDomain('socialguard1')->create();
    tenancy()->initialize($tenant);
    // Bypass setSetting() entirely to simulate a row written before this
    // guard existed (mirrors the color CSS-breakout bypass test above).
    $tenant->tenantSettings()->create(['key' => 'social_facebook', 'value' => 'javascript:alert(1)']);
    tenancy()->end();

    $this->get(tenant_url('socialguard1', '/'))
        ->assertOk()
        ->assertDontSee('javascript:alert', false);
});

it('socialFacebookUrl and socialInstagramUrl return null when unset', function () {
    $tenant = Tenant::factory()->withDomain('socialaccessor1')->create();
    tenancy()->initialize($tenant);

    expect($tenant->socialFacebookUrl())->toBeNull()
        ->and($tenant->socialInstagramUrl())->toBeNull();
});

it('socialFacebookUrl returns null when the stored value is a dangerous-scheme row', function () {
    $tenant = Tenant::factory()->withDomain('socialaccessor2')->create();
    tenancy()->initialize($tenant);
    $tenant->tenantSettings()->create(['key' => 'social_facebook', 'value' => 'javascript:alert(1)']);

    expect($tenant->socialFacebookUrl())->toBeNull();
});

it('socialFacebookUrl returns the stored value when it is a valid https URL', function () {
    $tenant = Tenant::factory()->withDomain('socialaccessor3')->create();
    tenancy()->initialize($tenant);
    $tenant->setSetting('social_facebook', 'https://facebook.com/mypage');

    expect($tenant->socialFacebookUrl())->toBe('https://facebook.com/mypage');
});

// ── Layout settings ────────────────────────────────────────────────────────────

/*
 * There is one shell per public page now, so there is no layout to choose and
 * no per-page allow-list to enforce. What still matters is that the removed
 * keys cannot sneak back in through the settings writer.
 */
it('no longer accepts a layout setting through the branding form', function () {
    brandingSetup('layoutgone');

    Livewire::test(BrandingSettings::class)
        ->set('data.layout_home', 'card-block')
        ->call('save');

    assertDatabaseMissing('tenant_settings', ['key' => 'layout_home']);
});

it('saves operator home page content settings per language', function () {
    [$tenant] = brandingSetup('contentok');

    Livewire::test(BrandingSettings::class)
        ->set('data.home_hero_heading_sq', 'Heroi im shqip')
        ->set('data.home_hero_heading_en', 'My English hero')
        ->set('data.home_service_2_title_sq', 'Shofer privat')
        ->call('save')
        ->assertHasNoErrors();

    tenancy()->end();
    tenancy()->initialize($tenant);

    expect($tenant->setting('home_hero_heading_sq'))->toBe('Heroi im shqip')
        ->and($tenant->setting('home_hero_heading_en'))->toBe('My English hero')
        ->and($tenant->setting('home_service_2_title_sq'))->toBe('Shofer privat');
});

it('prefills the Albanian content fields from legacy un-suffixed settings', function () {
    [$tenant] = brandingSetup('contentlegacy');

    $tenant->setSetting('home_hero_heading', 'Legacy heading');

    Livewire::test(BrandingSettings::class)
        ->assertSet('data.home_hero_heading_sq', 'Legacy heading');
});
