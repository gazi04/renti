@props([
    'page',
    'title',
    'description',
    'schema' => [],
])

{{--
    Shell for the central marketing site (<x-layouts::marketing>): SEO head, header,
    footer. Every URL comes from App\Enums\MarketingPage, which is also what the
    routes, the language switcher, the hreflang alternates and sitemap.xml are built
    from — so a page's translations always point at each other.
--}}
@php
    $currentPage = \App\Enums\MarketingPage::from($page);
    $locale = \App\Enums\MarketingPage::normalizeLocale(app()->getLocale());
    $otherLocale = $locale === 'sq' ? 'en' : 'sq';
    $ogLocales = ['sq' => 'sq_AL', 'en' => 'en_US'];

    // One JSON-LD document per page. Built here as a single variable because
    // Blade's @json() splits its argument on commas — an inline array literal
    // would not compile.
    $jsonLd = $schema === [] ? null : [
        '@context' => 'https://schema.org',
        '@graph' => array_values($schema),
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $title }}</title>
    <meta name="description" content="{{ $description }}">

    <link rel="canonical" href="{{ $currentPage->url($locale) }}">
    @foreach (\App\Enums\MarketingPage::LOCALES as $alternateLocale)
        <link rel="alternate" hreflang="{{ $alternateLocale }}" href="{{ $currentPage->url($alternateLocale) }}">
    @endforeach
    <link rel="alternate" hreflang="x-default" href="{{ $currentPage->url(\App\Enums\MarketingPage::DEFAULT_LOCALE) }}">

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ config('app.name') }}">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ $currentPage->url($locale) }}">
    <meta property="og:locale" content="{{ $ogLocales[$locale] }}">
    <meta property="og:locale:alternate" content="{{ $ogLocales[$otherLocale] }}">
    <meta name="twitter:card" content="summary">

    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">

    @if ($jsonLd !== null)
        {{-- @json's default flags hex-escape < and >, so no value can close this tag early. --}}
        <script type="application/ld+json">@json($jsonLd)</script>
    @endif

    @fonts(['poppins'])
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-dvh overflow-x-clip bg-surface-raised text-ink antialiased">

    {{-- Header. The nav links used to be `hidden sm:flex` with no replacement,
         so on a phone Features / Pricing were unreachable from the header
         entirely — the only route to them was the footer. --}}
    <header class="sticky top-0 z-40 border-b border-line bg-surface-raised/80 backdrop-blur">
        <x-ui.container>
            <div class="flex h-16 items-center justify-between gap-4">
                <a href="{{ \App\Enums\MarketingPage::Home->url() }}" class="flex min-w-0 items-center gap-2 text-lg font-bold text-ink">
                    <flux:icon.renti-logo class="h-7 w-auto text-primary" />
                    <span class="truncate">{{ config('app.name') }}</span>
                </a>

                <nav class="hidden items-center gap-8 text-sm font-medium text-ink-muted sm:flex">
                    <a href="{{ \App\Enums\MarketingPage::Home->url() }}#features" class="transition-colors hover:text-ink">{{ __('marketing.nav_features') }}</a>
                    <a href="{{ \App\Enums\MarketingPage::Pricing->url() }}" class="transition-colors hover:text-ink">{{ __('marketing.nav_pricing') }}</a>
                    <a href="{{ \App\Enums\MarketingPage::Faq->url() }}" class="transition-colors hover:text-ink">{{ __('marketing.nav_faq') }}</a>
                </nav>

                <div class="flex shrink-0 items-center gap-1">
                    {{-- A plain link to this page's translation, not a session toggle:
                         each language is its own crawlable URL. --}}
                    <a href="{{ $currentPage->url($otherLocale) }}"
                       hreflang="{{ $otherLocale }}"
                       lang="{{ $otherLocale }}"
                       data-language-switch
                       class="inline-flex min-h-11 items-center rounded-control px-2 text-sm text-ink-muted transition-colors hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                        {{ __('marketing.language_toggle') }}
                    </a>

                    {{-- Wrapper carries the responsive hide: x-ui.button's own
                         `inline-flex` base class overrides a `hidden` put
                         directly on it, so the button leaked onto phones and
                         squeezed the logo. --}}
                    <span class="hidden sm:inline-flex">
                        <x-ui.button :href="route('operator.register')" size="sm">
                            {{ __('marketing.nav_start_trial') }}
                        </x-ui.button>
                    </span>

                </div>
            </div>
        </x-ui.container>

        {{-- Mobile nav as a native <details>: no JavaScript at all. These pages
             load no Livewire, so they have no Alpine either, and a nav toggle is
             not worth a bundle or a third-party script. --}}
        <details class="group sm:hidden">
            <summary class="flex cursor-pointer list-none items-center justify-center gap-2 border-t border-line py-3 text-sm font-medium text-ink-muted marker:content-none hover:text-ink [&::-webkit-details-marker]:hidden">
                <flux:icon.bars-3 class="size-5 group-open:hidden" />
                <flux:icon.x-mark class="hidden size-5 group-open:block" />
                {{ __('marketing.nav_menu') }}
            </summary>

            <x-ui.container class="space-y-1 border-t border-line py-3 text-sm font-medium text-ink-muted">
                <a href="{{ \App\Enums\MarketingPage::Home->url() }}#features" class="flex min-h-11 items-center rounded-control px-2 hover:bg-surface hover:text-ink">{{ __('marketing.nav_features') }}</a>
                <a href="{{ \App\Enums\MarketingPage::Pricing->url() }}" class="flex min-h-11 items-center rounded-control px-2 hover:bg-surface hover:text-ink">{{ __('marketing.nav_pricing') }}</a>
                <a href="{{ \App\Enums\MarketingPage::Faq->url() }}" class="flex min-h-11 items-center rounded-control px-2 hover:bg-surface hover:text-ink">{{ __('marketing.nav_faq') }}</a>
                <x-ui.button :href="route('operator.register')" class="mt-2 w-full">
                    {{ __('marketing.nav_start_trial') }}
                </x-ui.button>
            </x-ui.container>
        </details>
    </header>

    <main>
        {{ $slot }}
    </main>

    {{-- Footer --}}
    <footer class="border-t border-line bg-surface">
        <div class="max-w-6xl mx-auto w-full px-4 sm:px-6 lg:px-8 py-10 sm:py-14">
            <div class="grid sm:grid-cols-3 gap-8 sm:gap-10 text-sm">
                <div>
                    <flux:icon.renti-logo class="h-6 w-auto text-primary" />
                    <p class="mt-2 font-semibold text-ink">{{ config('app.name') }}</p>
                    <p class="mt-2 text-ink-muted max-w-xs">{{ __('marketing.footer_tagline') }}</p>
                </div>
                <div>
                    <p class="font-semibold text-ink">{{ __('marketing.footer_product') }}</p>
                    <ul class="mt-2 space-y-2 text-ink-muted">
                        <li><a href="{{ \App\Enums\MarketingPage::Home->url() }}#features" class="hover:text-ink">{{ __('marketing.nav_features') }}</a></li>
                        <li><a href="{{ \App\Enums\MarketingPage::Pricing->url() }}" class="hover:text-ink">{{ __('marketing.nav_pricing') }}</a></li>
                        <li><a href="{{ \App\Enums\MarketingPage::Faq->url() }}" class="hover:text-ink">{{ __('marketing.nav_faq') }}</a></li>
                    </ul>
                </div>
                <div>
                    <p class="font-semibold text-ink">{{ __('marketing.footer_get_started') }}</p>
                    <ul class="mt-2 space-y-2 text-ink-muted">
                        <li><a href="{{ route('operator.register') }}" class="hover:text-ink">{{ __('marketing.nav_start_trial') }}</a></li>
                    </ul>
                </div>
            </div>

            <p class="mt-10 border-t border-line pt-6 text-sm text-ink-faint">
                &copy; {{ date('Y') }} {{ config('app.name') }}
            </p>
        </div>
    </footer>
</body>
</html>
