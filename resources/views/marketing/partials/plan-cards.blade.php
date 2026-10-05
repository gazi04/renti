{{-- One card per publicly-listed plan. $plans is Plan::publiclyListed(), bound to
     marketing.home and marketing.pricing by the view composer in
     AppServiceProvider and passed in by the including page. The price comes from
     the `plans` row; the tagline (marketing_description) and the bullet list
     (marketing_highlights) are curated per plan from the admin panel, on top of
     three baseline items every plan includes. --}}
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
