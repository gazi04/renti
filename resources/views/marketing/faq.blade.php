@php
    /** @var list<array{question: string, answer: string}> $faqItems */
    $faqItems = trans('marketing.faq_items');

    // The FAQPage schema is built from the very array the page renders: Google
    // only honours FAQ markup whose questions and answers are visible on the page.
    $schema = [
        [
            '@type' => 'FAQPage',
            'inLanguage' => app()->getLocale(),
            'mainEntity' => array_map(fn (array $item): array => [
                '@type' => 'Question',
                'name' => $item['question'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['answer']],
            ], $faqItems),
        ],
    ];
@endphp
<x-layouts::marketing
    :page="$page"
    :title="__('marketing.meta_faq_title')"
    :description="__('marketing.meta_faq_description')"
    :schema="$schema"
>
    <section class="bg-gradient-to-b from-primary/5 via-surface-raised to-surface-raised">
        <x-ui.container size="narrow" class="pt-14 sm:pt-20 pb-10 sm:pb-14 text-center">
            <h1 class="text-3xl sm:text-5xl font-bold tracking-tight text-balance text-ink">
                {{ __('marketing.faq_heading') }}
            </h1>
            <p class="mt-4 sm:mt-6 text-base sm:text-lg text-ink-muted">
                {{ __('marketing.faq_subheading') }}
            </p>
        </x-ui.container>
    </section>

    {{-- Native <details>: answers stay in the HTML (crawlable) with no JavaScript. --}}
    <x-ui.container size="narrow" class="pb-16 sm:pb-24">
        <div class="divide-y divide-line rounded-panel border border-line bg-surface-raised">
            @foreach ($faqItems as $item)
                <details class="group p-5 sm:p-6" data-faq-item>
                    <summary class="flex cursor-pointer list-none items-start justify-between gap-4 text-base font-semibold text-ink marker:content-none [&::-webkit-details-marker]:hidden">
                        <h2 class="text-base font-semibold">{{ $item['question'] }}</h2>
                        <flux:icon.chevron-down class="mt-0.5 size-5 shrink-0 text-ink-muted transition-transform group-open:rotate-180" />
                    </summary>
                    <p class="mt-3 text-sm sm:text-base text-ink-muted leading-relaxed">{{ $item['answer'] }}</p>
                </details>
            @endforeach
        </div>

        <div class="mt-10 text-center">
            <p class="text-ink-muted">{{ __('marketing.faq_cta_text') }}</p>
            <div class="mt-4 flex flex-col sm:flex-row items-center justify-center gap-3">
                <x-ui.button :href="route('operator.register')">{{ __('marketing.cta_button') }}</x-ui.button>
                <x-ui.button :href="\App\Enums\MarketingPage::Pricing->url()" variant="secondary">{{ __('marketing.hero_cta_secondary') }}</x-ui.button>
            </div>
        </div>
    </x-ui.container>
</x-layouts::marketing>
