@php
    $homeUrl = \App\Enums\MarketingPage::Home->url();
    $schema = [
        [
            '@type' => 'Organization',
            '@id' => $homeUrl.'#organization',
            'name' => config('app.name'),
            'url' => $homeUrl,
            'logo' => asset('apple-touch-icon.png'),
            'description' => __('marketing.footer_tagline'),
            'areaServed' => ['Kosovo', 'Albania', 'North Macedonia', 'Montenegro'],
        ],
        [
            '@type' => 'WebSite',
            '@id' => $homeUrl.'#website',
            'name' => config('app.name'),
            'url' => $homeUrl,
            'inLanguage' => app()->getLocale(),
            'publisher' => ['@id' => $homeUrl.'#organization'],
        ],
    ];
@endphp
<x-layouts::marketing
    :page="$page"
    :title="__('marketing.meta_home_title')"
    :description="__('marketing.meta_home_description')"
    :schema="$schema"
>
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

            @include('marketing.partials.plan-cards', ['plans' => $plans])
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
</x-layouts::marketing>
