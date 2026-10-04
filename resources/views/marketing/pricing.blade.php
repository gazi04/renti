@php
    $homeUrl = \App\Enums\MarketingPage::Home->url();
    $pricingUrl = \App\Enums\MarketingPage::Pricing->url();

    // Offers come from the same $plans the cards render, so the structured
    // prices can never drift from the visible ones.
    $schema = [
        [
            '@type' => 'SoftwareApplication',
            'name' => config('app.name'),
            'url' => $pricingUrl,
            'applicationCategory' => 'BusinessApplication',
            'operatingSystem' => 'Web',
            'description' => __('marketing.meta_pricing_description'),
            'publisher' => ['@id' => $homeUrl.'#organization'],
            'offers' => $plans->map(fn (\App\Models\Plan $plan): array => [
                '@type' => 'Offer',
                'name' => $plan->name,
                'price' => number_format((float) $plan->price, 2, '.', ''),
                'priceCurrency' => 'EUR',
                'url' => $pricingUrl,
            ])->values()->all(),
        ],
    ];

    // The numbers in the billing copy come from config/billing.php, so the
    // page can never promise a different trial or grace period than the sweep runs.
    $billingCopy = [
        'trial_days' => config('billing.trial_days'),
        'grace_days' => config('billing.grace_days'),
    ];
@endphp
<x-layouts::marketing
    :page="$page"
    :title="__('marketing.meta_pricing_title')"
    :description="__('marketing.meta_pricing_description')"
    :schema="$schema"
>
    {{-- Heading --}}
    <section class="bg-gradient-to-b from-primary/5 via-surface-raised to-surface-raised">
        <div class="max-w-6xl mx-auto w-full px-4 sm:px-6 lg:px-8 pt-14 sm:pt-20 pb-10 sm:pb-14 text-center">
            <h1 class="mx-auto max-w-3xl text-3xl sm:text-5xl font-bold tracking-tight text-balance text-ink">
                {{ __('marketing.pricing_page_heading') }}
            </h1>
            <p class="mt-4 sm:mt-6 mx-auto max-w-2xl text-base sm:text-lg text-ink-muted">
                {{ __('marketing.pricing_page_subheading') }}
            </p>
        </div>
    </section>

    {{-- Plans --}}
    <section id="plans" class="max-w-6xl mx-auto w-full px-4 sm:px-6 lg:px-8 pb-16 sm:pb-24 scroll-mt-20">
        @include('marketing.partials.plan-cards', ['plans' => $plans])
    </section>

    {{-- How billing works: the manual, no-card model is the selling point, not a footnote. --}}
    <section class="bg-surface border-y border-line">
        <div class="max-w-6xl mx-auto w-full px-4 sm:px-6 lg:px-8 py-16 sm:py-24">
            <div class="text-center max-w-2xl mx-auto mb-10 sm:mb-16">
                <h2 class="text-2xl sm:text-3xl font-bold text-ink">{{ __('marketing.billing_heading') }}</h2>
                <p class="mt-4 text-ink-muted">{{ __('marketing.billing_subheading') }}</p>
            </div>

            <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                @foreach (['trial', 'payment', 'reminders', 'commission'] as $step)
                    <div class="rounded-panel border border-line bg-surface-raised p-6">
                        <h3 class="text-base font-semibold text-ink">{{ __('marketing.billing_'.$step.'_title', $billingCopy) }}</h3>
                        <p class="mt-2 text-sm text-ink-muted leading-relaxed">{{ __('marketing.billing_'.$step.'_body', $billingCopy) }}</p>
                    </div>
                @endforeach
            </div>

            <p class="mt-10 text-center text-sm text-ink-muted">
                {{ __('marketing.billing_faq_prompt') }}
                <a href="{{ \App\Enums\MarketingPage::Faq->url() }}" class="font-semibold text-primary hover:text-secondary">{{ __('marketing.nav_faq') }}</a>
            </p>
        </div>
    </section>

    {{-- CTA band --}}
    <section class="relative overflow-hidden bg-gradient-to-r from-secondary to-primary">
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
