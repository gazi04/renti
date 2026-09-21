{{-- Full-bleed closing CTA. Static, translated copy — same destination as the
     hero's primary button. --}}
<div class="mx-auto max-w-2xl text-center">
    <h2 class="text-2xl font-bold text-balance text-ink-inverse sm:text-3xl">
        {{ __('booking.home_cta_heading') }}
    </h2>
    <p class="mt-3 text-ink-inverse/85">
        {{ __('booking.home_cta_subheading') }}
    </p>
    <div class="mt-8 flex justify-center">
        <x-ui.button :href="route('public.vehicles')" variant="on-dark" size="lg">
            {{ $content['hero_cta'] }}
        </x-ui.button>
    </div>
</div>
