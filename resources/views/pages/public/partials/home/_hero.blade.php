{{-- Two-column hero: copy + CTAs on a light surface, a decorative brand-gradient
     panel on the right stands in for an operator photo. --}}
<div class="grid items-center gap-12 lg:grid-cols-2">
    <div>
        <span class="inline-flex items-center gap-1.5 rounded-full bg-primary/10 px-3 py-1 text-xs font-semibold tracking-wide text-primary uppercase">
            <svg viewBox="0 0 20 20" class="size-3.5" fill="currentColor" aria-hidden="true">
                <path d="M10 1.5l2.6 5.3 5.9.8-4.3 4.1 1 5.8L10 14.7l-5.2 2.8 1-5.8-4.3-4.1 5.9-.8L10 1.5Z" />
            </svg>
            {{ __('booking.home_trust_badge') }}
        </span>

        <h1 class="mt-5 text-3xl font-bold tracking-tight text-balance break-words text-ink sm:text-4xl lg:text-5xl">
            {{ $content['hero_heading'] }}
        </h1>
        <p class="mt-5 max-w-lg text-base text-ink-muted sm:text-lg">
            {{ $content['hero_subheading'] }}
        </p>

        <div class="mt-8 flex flex-col gap-3 sm:flex-row">
            <x-ui.button :href="route('public.vehicles')" variant="primary" size="lg">
                {{ $content['hero_cta'] }}
                <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M4 12h16M13 5l7 7-7 7" />
                </svg>
            </x-ui.button>
            <x-ui.button href="#about" variant="secondary" size="lg">
                {{ __('booking.home_hero_secondary_cta') }}
            </x-ui.button>
        </div>
    </div>

    <div class="relative">
        <div class="relative flex aspect-4/3 items-center justify-center overflow-hidden rounded-panel bg-gradient-to-br from-primary to-ink">
            <svg viewBox="0 0 64 40" class="size-2/3 text-ink-inverse/55" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M6 26h4l4-9a4 4 0 0 1 3.7-2.5h20.6A4 4 0 0 1 42 17l4 9h4a4 4 0 0 1 4 4v3a2 2 0 0 1-2 2h-4" />
                <path d="M6 34a2 2 0 0 1-2-2v-3a3 3 0 0 1 3-3" />
                <circle cx="16" cy="34" r="4" />
                <circle cx="44" cy="34" r="4" />
                <path d="M20 34h20" />
            </svg>
            <span class="sr-only">{{ __('booking.home_hero_photo_placeholder') }}</span>
        </div>

        <x-ui.card pad="sm" class="absolute -bottom-5 left-5 flex items-center gap-3 shadow-lg">
            <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-positive-surface text-positive">
                <flux:icon.shield-check class="size-5" />
            </span>
            <span class="text-sm">
                <span class="block font-semibold text-ink">{{ __('booking.home_fully_insured_badge') }}</span>
                <span class="block text-xs text-ink-faint">{{ __('booking.home_fully_insured_subtext') }}</span>
            </span>
        </x-ui.card>
    </div>
</div>
