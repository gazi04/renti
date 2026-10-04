{{-- The XML declaration is prepended by SitemapController: a literal one here
     would be parsed as a PHP open tag. --}}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">
@foreach ($pages as $page)
@foreach (\App\Enums\MarketingPage::LOCALES as $locale)
    <url>
        <loc>{{ $page->url($locale) }}</loc>
@foreach (\App\Enums\MarketingPage::LOCALES as $alternateLocale)
        <xhtml:link rel="alternate" hreflang="{{ $alternateLocale }}" href="{{ $page->url($alternateLocale) }}"/>
@endforeach
        <xhtml:link rel="alternate" hreflang="x-default" href="{{ $page->url(\App\Enums\MarketingPage::DEFAULT_LOCALE) }}"/>
    </url>
@endforeach
@endforeach
</urlset>
