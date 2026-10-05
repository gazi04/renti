<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\Tenant;

/*
 * The central marketing site's SEO contract (routes/web.php, App\Enums\MarketingPage):
 * every page exists once per language under a /sq or /en prefix with a translated
 * slug, Albanian is the default, and each page tells crawlers about its
 * translation (canonical + hreflang), its structured data and the sitemap.
 */

afterEach(fn () => tenancy()->end());

function seoUrl(string $path): string
{
    return 'http://'.config('tenancy.central_domain').$path;
}

/** @return array<string, mixed> the page's decoded JSON-LD document */
function jsonLdOf(string $html): array
{
    preg_match('#<script type="application/ld\+json">(.+?)</script>#s', $html, $matches);

    expect($matches)->toHaveKey(1, message: 'page has no JSON-LD block');

    return json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
}

it('sends the bare root to the Albanian home page by default', function () {
    $this->get(seoUrl('/'))->assertRedirect(seoUrl('/sq'));
});

it('sends the bare root to the language the visitor last read, keeping the query string', function () {
    $this->withSession(['locale' => 'en'])
        ->get(seoUrl('/?verified=1'))
        ->assertRedirect(seoUrl('/en?verified=1'));
});

it('serves each page in its language with a self canonical and both translations as alternates', function (string $path, string $lang, string $sqPath, string $enPath, string $titleKey) {
    $response = $this->get(seoUrl($path));

    $response->assertOk()
        ->assertSee('<html lang="'.$lang.'">', escape: false)
        ->assertSee('<title>'.e(trans($titleKey, locale: $lang)).'</title>', escape: false)
        ->assertSee('<meta name="description" content="', escape: false)
        ->assertSee('<link rel="canonical" href="'.seoUrl($path).'">', escape: false)
        ->assertSee('<link rel="alternate" hreflang="sq" href="'.seoUrl($sqPath).'">', escape: false)
        ->assertSee('<link rel="alternate" hreflang="en" href="'.seoUrl($enPath).'">', escape: false)
        ->assertSee('<link rel="alternate" hreflang="x-default" href="'.seoUrl($sqPath).'">', escape: false);

    $otherLang = $lang === 'sq' ? 'en' : 'sq';
    $otherPath = $lang === 'sq' ? $enPath : $sqPath;
    preg_match('#<a href="([^"]+)"\s+hreflang="'.$otherLang.'"\s+lang="'.$otherLang.'"\s+data-language-switch#', $response->getContent(), $switch);
    expect($switch[1] ?? null)->toBe(seoUrl($otherPath));

    expect(jsonLdOf($response->getContent()))->toHaveKey('@context', 'https://schema.org');
})->with([
    'home sq' => ['/sq', 'sq', '/sq', '/en', 'marketing.meta_home_title'],
    'home en' => ['/en', 'en', '/sq', '/en', 'marketing.meta_home_title'],
    'pricing sq' => ['/sq/cmimet', 'sq', '/sq/cmimet', '/en/pricing', 'marketing.meta_pricing_title'],
    'pricing en' => ['/en/pricing', 'en', '/sq/cmimet', '/en/pricing', 'marketing.meta_pricing_title'],
    'faq sq' => ['/sq/pyetje-te-shpeshta', 'sq', '/sq/pyetje-te-shpeshta', '/en/faq', 'marketing.meta_faq_title'],
    'faq en' => ['/en/faq', 'en', '/sq/pyetje-te-shpeshta', '/en/faq', 'marketing.meta_faq_title'],
]);

it('remembers the language of the page read so signup follows it', function () {
    $this->get(seoUrl('/en/pricing'))->assertSessionHas('locale', 'en');
});

it('does not serve an unsupported language prefix', function () {
    $this->get(seoUrl('/de'))->assertNotFound();
});

it('does not serve the marketing pages on a tenant storefront host', function () {
    Tenant::factory()->withDomain('seohost')->create();

    $this->get(tenant_url('seohost', '/sq/cmimet'))->assertNotFound();
});

it('marks up exactly the FAQ items shown on the page', function () {
    $html = $this->get(seoUrl('/en/faq'))->getContent();

    $faqPage = collect(jsonLdOf($html)['@graph'])->firstWhere('@type', 'FAQPage');

    expect($faqPage['mainEntity'])->toHaveCount(substr_count($html, 'data-faq-item'))
        ->and($faqPage['mainEntity'])->not->toBeEmpty();

    foreach ($faqPage['mainEntity'] as $question) {
        expect($html)->toContain(e($question['name']), e($question['acceptedAnswer']['text']));
    }
});

it('marks up the live plan prices as offers on the pricing page', function () {
    Plan::query()->delete();
    Plan::factory()->create([
        'name' => 'Standard', 'slug' => 'standard', 'price' => 33,
        'is_active' => true, 'is_public' => true, 'sort_order' => 1,
    ]);

    $html = $this->get(seoUrl('/sq/cmimet'))->getContent();

    $app = collect(jsonLdOf($html)['@graph'])->firstWhere('@type', 'SoftwareApplication');

    expect($app['offers'])->toBe([[
        '@type' => 'Offer',
        'name' => 'Standard',
        'price' => '33.00',
        'priceCurrency' => 'EUR',
        'url' => seoUrl('/sq/cmimet'),
    ]]);
});

it('states the trial and grace periods the billing sweep actually runs', function () {
    config(['billing.trial_days' => 45, 'billing.grace_days' => 9]);

    $this->get(seoUrl('/en/pricing'))
        ->assertSee('45 days free')
        ->assertSee('you still have 9 days before');
});

it('lists every page in every language, with its alternates, in the sitemap', function () {
    $response = $this->get(seoUrl('/sitemap.xml'));

    $response->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

    $xml = simplexml_load_string($response->getContent());
    $locs = array_map('strval', $xml->xpath('//*[local-name()="loc"]'));

    expect($locs)->toEqualCanonicalizing([
        seoUrl('/sq'), seoUrl('/en'),
        seoUrl('/sq/cmimet'), seoUrl('/en/pricing'),
        seoUrl('/sq/pyetje-te-shpeshta'), seoUrl('/en/faq'),
    ]);

    expect($xml->xpath('//*[local-name()="link"]'))->toHaveCount(6 * 3);
});

it('advertises the sitemap in robots.txt on the central host only', function () {
    $this->get(seoUrl('/robots.txt'))
        ->assertOk()
        ->assertSee('Sitemap: '.seoUrl('/sitemap.xml'));

    $this->get(tenant_url('anyshop', '/robots.txt'))
        ->assertOk()
        ->assertSee("User-agent: *\nDisallow:", escape: false)
        ->assertDontSee('Sitemap:');
});
