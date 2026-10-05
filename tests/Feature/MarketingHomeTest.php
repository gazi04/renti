<?php

declare(strict_types=1);

use App\Enums\PlanFeature;
use App\Models\Plan;

/*
 * The public SaaS landing page (routes/web.php, central domain only).
 *
 * The pricing table used to hardcode €15/€29/€49 in the Blade markup while the
 * real prices live in the `plans` table and are editable from the admin panel.
 * These tests pin the fix: the page reads live plan data.
 */

function marketingUrl(string $path = '/sq'): string
{
    return 'http://'.config('tenancy.central_domain').$path;
}

it('renders the marketing page on the central domain', function () {
    $this->get(marketingUrl())
        ->assertOk()
        ->assertSee(__('marketing.hero_heading'))
        ->assertSee(__('marketing.pricing_heading'))
        // Mobile-UX guards: horizontal-scroll clip on <body>, sticky-header
        // offset on the anchor-target sections, and the wrapper that actually
        // hides the header CTA on phones (x-ui.button's own `inline-flex` base
        // overrides a `hidden` placed directly on it).
        ->assertSee('overflow-x-clip', escape: false)
        ->assertSee('scroll-mt-20', escape: false)
        ->assertSee('<span class="hidden sm:inline-flex">', escape: false);
});

it('renders each plan card from the admin-curated tagline and highlights, localized', function () {
    Plan::query()->delete();

    Plan::factory()
        ->withFeatures([
            PlanFeature::Reports->value => true,
            PlanFeature::PromoCodes->value => true,
            PlanFeature::FleetHeatmap->value => false,
        ])
        ->withHighlights([PlanFeature::Reports->value, PlanFeature::PromoCodes->value])
        ->create([
            'name' => 'Standard', 'slug' => 'standard', 'price' => 29,
            'is_active' => true, 'is_public' => true, 'sort_order' => 2,
            'marketing_description' => ['en' => 'For growing fleets.', 'sq' => 'Për flota në rritje.'],
        ]);

    // Default locale (sq): curated tagline + only the picked highlight bullets.
    $this->get(marketingUrl())
        ->assertOk()
        ->assertSee('Për flota në rritje.')
        ->assertSee(__('marketing.plan_feature_reports'))
        ->assertSee(__('marketing.plan_feature_promo_codes'))
        ->assertDontSee(__('marketing.plan_feature_fleet_heatmap'))
        // clamp + equal-height grid + non-wrapping "Most popular" badge.
        ->assertSee('line-clamp-1', escape: false)
        ->assertSee('items-stretch', escape: false)
        ->assertSee('whitespace-nowrap', escape: false);

    // The tagline follows the page's language.
    $this->get(marketingUrl('/en'))
        ->assertOk()
        ->assertSee('For growing fleets.')
        ->assertDontSee('Për flota në rritje.');
});

it('prices the plan cards from the database, not from the markup', function () {
    Plan::query()->delete();

    Plan::factory()->create([
        'name' => 'Basic', 'slug' => 'basic', 'price' => 15,
        'is_active' => true, 'is_trial' => false, 'sort_order' => 2,
    ]);

    // An admin editing this price must move the public page with it.
    Plan::factory()->create([
        'name' => 'Standard', 'slug' => 'standard', 'price' => 33,
        'is_active' => true, 'is_trial' => false, 'sort_order' => 3,
    ]);

    $this->get(marketingUrl())
        ->assertOk()
        ->assertSee('€33')
        ->assertDontSee('€29');
});

it('omits archived plans from the pricing table', function () {
    Plan::query()->delete();

    Plan::factory()->create([
        'name' => 'Basic', 'slug' => 'basic', 'price' => 15,
        'is_active' => true, 'is_trial' => false, 'sort_order' => 2,
    ]);
    Plan::factory()->create([
        'name' => 'Retired', 'slug' => 'retired', 'price' => 99,
        'is_active' => false, 'is_trial' => false, 'sort_order' => 9,
    ]);

    $this->get(marketingUrl())
        ->assertOk()
        ->assertSee('€15')
        ->assertDontSee('€99');
});

it('shows the free label rather than a zero price for the trial tier', function () {
    Plan::query()->delete();

    Plan::factory()->create([
        'name' => 'Trial', 'slug' => 'trial', 'price' => 0,
        'is_active' => true, 'is_trial' => true, 'sort_order' => 1,
    ]);

    $this->get(marketingUrl())
        ->assertOk()
        ->assertSee(__('marketing.plan_trial_price'))
        ->assertDontSee('€0');
});

it('hides an unlisted plan from the pricing page but a listed one shows', function () {
    Plan::query()->delete();

    Plan::factory()->create([
        'name' => 'Public Co', 'slug' => 'pub', 'price' => 20,
        'is_active' => true, 'is_public' => true, 'sort_order' => 1,
    ]);
    Plan::factory()->unlisted()->create([
        'name' => 'Client Alpha Custom', 'slug' => 'alpha', 'price' => 250,
        'is_active' => true, 'sort_order' => 2,
    ]);

    $this->get(marketingUrl())
        ->assertOk()
        ->assertSee('Public Co')
        ->assertDontSee('Client Alpha Custom');
});

it('shows at most four plans on the pricing page', function () {
    Plan::query()->delete();

    foreach (range(1, 6) as $i) {
        Plan::factory()->create([
            'name' => "Tier {$i}", 'slug' => "tier-{$i}", 'price' => $i * 10,
            'is_active' => true, 'is_public' => true, 'sort_order' => $i,
        ]);
    }

    $this->get(marketingUrl())
        ->assertOk()
        ->assertSee('Tier 4')
        ->assertDontSee('Tier 5')
        ->assertDontSee('Tier 6');
});

it('exposes the nav sections to phones through a no-javascript disclosure', function () {
    // The links were `hidden sm:flex` with no replacement, so Features / How it
    // works / Pricing were unreachable from the header on a phone. Pricing is now
    // its own page, so the menu links to it rather than to the #pricing anchor.
    $this->get(marketingUrl())
        ->assertOk()
        ->assertSee('<details', escape: false)
        ->assertSee('#features', escape: false)
        ->assertSee('href="'.marketingUrl('/sq/cmimet').'"', escape: false);
});

it('loads no third-party assets', function () {
    $html = $this->get(marketingUrl())->assertOk()->getContent();

    expect($html)
        ->not->toContain('fonts.googleapis.com')
        ->not->toContain('fonts.gstatic.com')
        ->not->toContain('cdn.jsdelivr.net');
});
