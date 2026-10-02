{{-- Home-page review showcase — gated (Standard/Pro). Aggregate rating + a few
     featured approved reviews across the fleet. --}}
@php
    $reviews = $this->showcaseReviews;
    $average = $this->averageRating;
    $count = $this->reviewsCount;
@endphp

<section>
    <div class="mb-8 text-center">
        <h2 class="mb-2 text-2xl font-bold text-ink sm:text-3xl">{{ __('booking.reviews_heading') }}</h2>
        <div class="inline-flex flex-wrap items-center justify-center gap-2 text-ink-muted">
            <x-ui.stars :rating="$average" size="lg" />
            <span class="text-lg font-semibold text-ink">{{ number_format((float) $average, 1) }}</span>
            <span class="text-ink-faint">/ 5</span>
            <span class="text-ink-faint" aria-hidden="true">·</span>
            <span>{{ trans_choice('booking.reviews_count', $count, ['count' => $count]) }}</span>
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($reviews as $review)
            <x-ui.card>
                <x-ui.stars :rating="$review->rating" class="mb-3" />
                @if ($review->comment)
                    <p class="mb-4 text-sm leading-relaxed text-ink-muted">&ldquo;{{ $review->comment }}&rdquo;</p>
                @endif
                <div class="flex items-center gap-3">
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-sm font-semibold text-primary">
                        {{ Str::of($review->reviewer_name)->explode(' ')->map(fn ($part) => Str::substr($part, 0, 1))->take(2)->join('') }}
                    </span>
                    <span>
                        <span class="block text-sm font-medium text-ink">{{ $review->reviewer_name }}</span>
                        <span class="block text-xs text-ink-faint">{{ $review->vehicle->name }}</span>
                    </span>
                </div>
            </x-ui.card>
        @endforeach
    </div>
</section>
