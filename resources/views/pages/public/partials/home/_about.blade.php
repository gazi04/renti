{{-- Operator-written about text, plus real-data stats (never invented ones). --}}
@php
    $showsRatingStat = ($tenant = tenant())
        && ($tenant->allowsFeature(\App\Enums\PlanFeature::Reviews) ?? (bool) \App\Enums\PlanFeature::Reviews->default())
        && $this->reviewsCount > 0;
@endphp

<section id="about" class="max-w-3xl scroll-mt-20">
    <div class="grid items-start gap-8 sm:grid-cols-2">
        <div>
            <x-ui.section-heading>{{ $content['about_title'] }}</x-ui.section-heading>
            <p class="mt-4 leading-relaxed text-ink-muted break-words">{!! nl2br(e($content['about_text'])) !!}</p>

            <a href="#contact" class="mt-4 inline-flex min-h-11 items-center gap-1 text-sm font-medium text-primary hover:underline">
                {{ __('booking.home_about_get_in_touch') }} <span aria-hidden="true">&rarr;</span>
            </a>
        </div>

        <div class="grid {{ $showsRatingStat ? 'grid-cols-2' : 'grid-cols-1' }} gap-4">
            <div class="rounded-panel bg-surface-sunken p-5">
                <p class="text-2xl font-bold text-primary">{{ $this->fleetSize }}</p>
                <p class="mt-1 text-sm text-ink-muted">{{ __('booking.home_stat_fleet_size') }}</p>
            </div>

            @if ($showsRatingStat)
                <div class="rounded-panel bg-surface-sunken p-5">
                    <p class="text-2xl font-bold text-primary">{{ number_format($this->averageRating, 1) }} <span aria-hidden="true" class="text-star">★</span></p>
                    <p class="mt-1 text-sm text-ink-muted">{{ __('booking.home_stat_average_rating') }}</p>
                </div>
            @endif
        </div>
    </div>
</section>
