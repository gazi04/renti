<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ config('app.name') }} — {{ __('marketing.hero_heading') }}</title>

    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">

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
                <a href="{{ route('home') }}" class="flex min-w-0 items-center gap-2 text-lg font-bold text-ink">
                    <flux:icon.renti-logo class="h-7 w-auto text-primary" />
                    <span class="truncate">{{ config('app.name') }}</span>
                </a>

                <nav class="hidden items-center gap-8 text-sm font-medium text-ink-muted sm:flex">
                    <a href="#features" class="transition-colors hover:text-ink">{{ __('marketing.nav_features') }}</a>
                    <a href="#pricing" class="transition-colors hover:text-ink">{{ __('marketing.nav_pricing') }}</a>
                </nav>

                <div class="flex shrink-0 items-center gap-1">
                    <form method="POST" action="{{ route('marketing.language') }}">
                        @csrf
                        <input type="hidden" name="locale" value="{{ app()->getLocale() === 'sq' ? 'en' : 'sq' }}">
                        <button type="submit"
                                class="inline-flex min-h-11 items-center rounded-control px-2 text-sm text-ink-muted transition-colors hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                            {{ __('marketing.language_toggle') }}
                        </button>
                    </form>

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

        {{-- Mobile nav as a native <details>: no JavaScript at all. This page
             loads no Livewire, so it has no Alpine either, and a nav toggle is
             not worth a bundle or a third-party script. --}}
        <details class="group sm:hidden">
            <summary class="flex cursor-pointer list-none items-center justify-center gap-2 border-t border-line py-3 text-sm font-medium text-ink-muted marker:content-none hover:text-ink [&::-webkit-details-marker]:hidden">
                <flux:icon.bars-3 class="size-5 group-open:hidden" />
                <flux:icon.x-mark class="hidden size-5 group-open:block" />
                {{ __('marketing.nav_menu') }}
            </summary>

            <x-ui.container class="space-y-1 border-t border-line py-3 text-sm font-medium text-ink-muted">
                <a href="#features" class="flex min-h-11 items-center rounded-control px-2 hover:bg-surface hover:text-ink">{{ __('marketing.nav_features') }}</a>
                <a href="#pricing" class="flex min-h-11 items-center rounded-control px-2 hover:bg-surface hover:text-ink">{{ __('marketing.nav_pricing') }}</a>
                <x-ui.button :href="route('operator.register')" class="mt-2 w-full">
                    {{ __('marketing.nav_start_trial') }}
                </x-ui.button>
            </x-ui.container>
        </details>
    </header>

    {{-- Hero --}}
    <section class="relative overflow-hidden bg-gradient-to-b from-primary/5 via-surface-raised to-surface-raised">
        <div class="pointer-events-none absolute inset-x-0 -top-40 h-96 bg-[radial-gradient(60%_60%_at_50%_0%,rgba(37,99,235,0.12),transparent)]"></div>

        <div class="relative max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 pt-14 sm:pt-28 pb-12 sm:pb-16">
            <div class="mx-auto max-w-3xl text-center">
                <span class="inline-flex items-center rounded-full border border-primary/20 bg-primary/10 px-3 py-1 text-center text-[11px] sm:text-xs font-medium text-secondary">
                    {{ __('marketing.hero_badge') }}
                </span>

                <h1 class="mt-6 text-3xl sm:text-5xl lg:text-6xl font-bold tracking-tight text-balance break-words text-ink">
                    {{ __('marketing.hero_heading') }}
                </h1>
                <p class="mt-4 sm:mt-6 text-base sm:text-lg text-ink-muted max-w-2xl mx-auto">
                    {{ __('marketing.hero_subheading') }}
                </p>

                <div class="mt-10 flex flex-col sm:flex-row items-center justify-center gap-4">
                    <a href="{{ route('operator.register') }}"
                       class="w-full sm:w-auto rounded-control bg-primary px-6 py-3 text-base font-semibold text-on-primary shadow-lg shadow-primary/20 hover:bg-secondary transition-colors">
                        {{ __('marketing.hero_cta_primary') }}
                    </a>
                    <a href="#pricing"
                       class="w-full sm:w-auto rounded-control border border-line-strong bg-surface-raised px-6 py-3 text-base font-semibold text-ink-muted hover:bg-surface transition-colors">
                        {{ __('marketing.hero_cta_secondary') }}
                    </a>
                </div>
                <p class="mt-4 text-sm text-ink-muted">{{ __('marketing.hero_note') }}</p>
            </div>

            {{-- Floating "set up your site" mini-card. Pure decoration on a
                 phone (adds height, no information), so it only renders from
                 sm up, same treatment as the rest of the page's heavy visuals. --}}
            <div class="mt-10 sm:mt-16 mx-auto hidden max-w-xs rotate-2 rounded-panel border border-line bg-surface-raised p-5 shadow-2xl shadow-ink/10 sm:block">
                <p class="text-xs font-bold text-ink-faint">{{ __('marketing.hero_setup_title') }}</p>
                <div class="mt-3 flex flex-col gap-2">
                    <div class="flex items-center gap-2.5 rounded-control bg-primary/10 px-3.5 py-3">
                        <span class="flex size-5 shrink-0 items-center justify-center rounded-full bg-primary text-[11px] font-bold text-on-primary">1</span>
                        <span class="text-sm font-semibold text-ink">{{ __('marketing.hero_setup_step_1') }}</span>
                    </div>
                    <div class="flex items-center gap-2.5 rounded-control bg-surface px-3.5 py-3">
                        <span class="flex size-5 shrink-0 items-center justify-center rounded-full bg-line text-[11px] font-bold text-ink-faint">2</span>
                        <span class="text-sm font-semibold text-ink-faint">{{ __('marketing.hero_setup_step_2') }}</span>
                    </div>
                    <div class="flex items-center gap-2.5 rounded-control bg-surface px-3.5 py-3">
                        <span class="flex size-5 shrink-0 items-center justify-center rounded-full bg-line text-[11px] font-bold text-ink-faint">3</span>
                        <span class="text-sm font-semibold text-ink-faint">{{ __('marketing.hero_setup_step_3') }}</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- Stats strip --}}
    <section class="border-y border-line bg-surface">
        <div class="max-w-6xl mx-auto w-full px-4 sm:px-6 lg:px-8 py-8 sm:py-10 grid sm:grid-cols-3 gap-8 text-center">
            <div>
                <p class="text-2xl font-bold text-ink">{{ __('marketing.stat_setup_value') }}</p>
                <p class="mt-1 text-sm text-ink-muted">{{ __('marketing.stat_setup_label') }}</p>
            </div>
            <div>
                <p class="text-2xl font-bold text-ink">{{ __('marketing.stat_languages_value') }}</p>
                <p class="mt-1 text-sm text-ink-muted">{{ __('marketing.stat_languages_label') }}</p>
            </div>
            <div>
                <p class="text-2xl font-bold text-ink">{{ __('marketing.stat_commission_value') }}</p>
                <p class="mt-1 text-sm text-ink-muted">{{ __('marketing.stat_commission_label') }}</p>
            </div>
        </div>
    </section>

    {{-- About --}}
    <section class="bg-surface border-b border-line">
        <div class="max-w-6xl mx-auto w-full px-4 sm:px-6 lg:px-8 py-16 sm:py-24 grid gap-10 sm:gap-16 lg:grid-cols-2 lg:items-center">
            <div>
                <p class="text-xs font-bold tracking-wide text-primary uppercase">{{ __('marketing.about_eyebrow') }}</p>
                <h2 class="mt-3 text-2xl sm:text-3xl font-bold text-ink text-balance">{{ __('marketing.about_heading') }}</h2>
                <p class="mt-4 text-ink-muted leading-relaxed">{{ __('marketing.about_body') }}</p>
            </div>

            <div class="relative h-56 overflow-hidden rounded-panel bg-gradient-to-br from-primary to-secondary sm:h-64">
                <flux:icon.truck class="absolute -right-6 -bottom-6 size-48 text-on-primary/20" />
                <p class="absolute left-6 top-6 text-base font-bold text-on-primary">
                    {{ __('marketing.about_panel_line') }}
                </p>
            </div>
        </div>
    </section>

    {{-- Features --}}
    <section id="features" class="max-w-6xl mx-auto w-full px-4 sm:px-6 lg:px-8 py-16 sm:py-24 scroll-mt-20">
        <div class="text-center max-w-2xl mx-auto mb-10 sm:mb-16">
            <h2 class="text-2xl sm:text-3xl font-bold text-ink">{{ __('marketing.features_heading') }}</h2>
            <p class="mt-4 text-ink-muted">{{ __('marketing.features_subheading') }}</p>
        </div>

        <div class="grid sm:grid-cols-3 gap-8">
            @foreach ([
                ['key' => 'brand', 'tone' => 'bg-primary/10 text-primary', 'icon' => 'M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z'],
                ['key' => 'bookings', 'tone' => 'bg-secondary/10 text-secondary', 'icon' => 'M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z'],
                ['key' => 'money', 'tone' => 'bg-primary/10 text-primary', 'icon' => 'M21 12a2.25 2.25 0 00-2.25-2.25H15a3 3 0 11-6 0H5.25A2.25 2.25 0 003 12m18 0v6a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 18v-6m18 0V9M3 12V9m18 0a2.25 2.25 0 00-2.25-2.25H5.25A2.25 2.25 0 003 9m18 0V6a2.25 2.25 0 00-2.25-2.25H5.25A2.25 2.25 0 003 6v3'],
            ] as $feature)
                <div class="rounded-panel border border-line p-6 sm:p-8 hover:border-primary/20 hover:shadow-md transition-all">
                    <span @class(['flex h-11 w-11 items-center justify-center rounded-control mb-5', $feature['tone']])>
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="{{ $feature['icon'] }}"/>
                        </svg>
                    </span>
                    <h3 class="text-lg font-semibold text-ink mb-2">{{ __('marketing.feature_' . $feature['key'] . '_title') }}</h3>
                    <p class="text-sm text-ink-muted leading-relaxed">{{ __('marketing.feature_' . $feature['key'] . '_body') }}</p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Product demo --}}
    <section class="max-w-6xl mx-auto w-full px-4 sm:px-6 lg:px-8 py-16 sm:py-24">
        <div class="text-center max-w-2xl mx-auto mb-10 sm:mb-16">
            <h2 class="text-2xl sm:text-3xl font-bold text-ink">{{ __('marketing.demo_heading') }}</h2>
            <p class="mt-4 text-ink-muted">{{ __('marketing.demo_subheading') }}</p>
        </div>

        <div class="mx-auto max-w-4xl rounded-panel border border-line bg-surface-raised shadow-2xl shadow-ink/10 overflow-hidden text-left">
            <div class="flex items-center gap-2 border-b border-line bg-surface px-4 py-3">
                <span class="size-3 rounded-full bg-critical/40"></span>
                <span class="size-3 rounded-full bg-notice/40"></span>
                <span class="size-3 rounded-full bg-positive/40"></span>
                <span class="ml-4 flex-1 max-w-sm rounded-control bg-surface-raised border border-line px-3 py-1 text-xs text-ink-faint">
                    {{ __('marketing.hero_mock_url') }}
                </span>
            </div>
            <div class="grid grid-cols-2 gap-3 p-4 sm:grid-cols-3 sm:gap-4 sm:p-6">
                <div class="col-span-2 h-20 rounded-control bg-gradient-to-r from-primary to-secondary sm:col-span-3"></div>
                @for ($i = 0; $i < 6; $i++)
                    <div class="rounded-control border border-line p-3">
                        <div class="aspect-video rounded-control bg-surface-sunken"></div>
                        <div class="mt-2 h-2.5 w-3/4 rounded-control bg-line"></div>
                        <div class="mt-1.5 h-2.5 w-1/2 rounded-control bg-surface-sunken"></div>
                        <div class="mt-3 h-6 w-20 rounded-control bg-primary/15"></div>
                    </div>
                @endfor
            </div>
        </div>

        <p class="mt-6 text-center text-sm text-ink-muted">{{ __('marketing.demo_caption') }}</p>
    </section>

    {{-- Pricing --}}
    <section id="pricing" class="bg-surface border-y border-line scroll-mt-20">
        <div class="max-w-6xl mx-auto w-full px-4 sm:px-6 lg:px-8 py-16 sm:py-24">
            <div class="text-center max-w-2xl mx-auto mb-10 sm:mb-16">
                <h2 class="text-2xl sm:text-3xl font-bold text-ink">{{ __('marketing.pricing_heading') }}</h2>
                <p class="mt-4 text-ink-muted">{{ __('marketing.pricing_subheading') }}</p>
            </div>

            {{-- One card per publicly-listed plan (Plan::publiclyListed(), via the
                 view composer in AppServiceProvider). The price comes from the
                 `plans` row; the tagline (marketing_description) and the bullet list
                 (marketing_highlights) are curated per plan from the admin panel, on
                 top of three baseline items every plan includes. --}}
            <div class="grid items-stretch gap-6 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($plans as $plan)
                    @php($isFeatured = $plan->slug === 'standard')

                    <div @class([
                        'relative flex h-full flex-col rounded-panel bg-surface-raised',
                        'border-2 border-primary shadow-lg shadow-primary/10 px-6 pb-6 pt-9' => $isFeatured,
                        'border border-line p-6' => ! $isFeatured,
                    ]) wire:key="plan-{{ $plan->slug }}">
                        @if ($isFeatured)
                            <span class="absolute -top-3 left-1/2 -translate-x-1/2 whitespace-nowrap rounded-full bg-primary px-3 py-1 text-xs font-semibold text-on-primary">
                                {{ __('marketing.plan_standard_badge') }}
                            </span>
                        @endif

                        <h3 class="text-base font-semibold text-ink">{{ $plan->name }}</h3>

                        <p class="mt-4">
                            @if ((float) $plan->price <= 0)
                                <span class="text-3xl font-bold text-ink">{{ __('marketing.plan_trial_price') }}</span>
                            @else
                                <span class="text-3xl font-bold text-ink">{{ __('booking.currency_symbol') }}{{ number_format((float) $plan->price, 0) }}</span>
                            @endif
                            <span class="text-sm text-ink-muted">{{ (float) $plan->price <= 0 ? __('marketing.plan_period_trial') : __('marketing.plan_period_monthly') }}</span>
                        </p>

                        <p class="mt-3 line-clamp-1 text-sm text-ink-muted">{{ $plan->marketingTagline() }}</p>

                        <ul class="mt-6 flex-1 space-y-2 text-sm text-ink-muted">
                            {{-- Baseline value, on every card. --}}
                            @foreach (['dashboard', 'agreements', 'bilingual'] as $feature)
                                <li class="flex items-start gap-2">
                                    <flux:icon.check class="mt-0.5 size-4 shrink-0 text-positive" />
                                    {{ __('marketing.plan_feature_'.$feature) }}
                                </li>
                            @endforeach

                            {{-- Admin-picked highlights for this plan (marketing_highlights). --}}
                            @foreach ($plan->marketingHighlightLines() as $line)
                                <li class="flex items-start gap-2">
                                    <flux:icon.check class="mt-0.5 size-4 shrink-0 text-positive" />
                                    {{ $line }}
                                </li>
                            @endforeach
                        </ul>

                        <x-ui.button :href="route('operator.register')"
                                     :variant="$isFeatured ? 'primary' : 'secondary'"
                                     class="mt-6 w-full">
                            {{ __('marketing.plan_cta') }}
                        </x-ui.button>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- CTA band --}}
    <section class="relative overflow-hidden bg-gradient-to-r from-secondary to-primary">
        <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(50%_100%_at_50%_0%,rgba(255,255,255,0.12),transparent)]"></div>
        <div class="relative max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-14 sm:py-20 text-center">
            <h2 class="text-2xl sm:text-3xl font-bold text-on-primary">{{ __('marketing.cta_heading') }}</h2>
            <p class="mt-3 text-on-primary/80">{{ __('marketing.cta_subheading') }}</p>
            <a href="{{ route('operator.register') }}"
               class="mt-8 inline-flex w-full sm:w-auto items-center justify-center rounded-control bg-surface-raised px-6 py-3 text-base font-semibold text-secondary hover:bg-primary/10 transition-colors">
                {{ __('marketing.cta_button') }}
            </a>
        </div>
    </section>

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
                        <li><a href="#features" class="hover:text-ink">{{ __('marketing.nav_features') }}</a></li>
                        <li><a href="#pricing" class="hover:text-ink">{{ __('marketing.nav_pricing') }}</a></li>
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
