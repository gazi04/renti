{{-- Vehicle title bar: breadcrumb, name, rating (when reviewed), quick specs.
     Category now shown as a badge over the gallery photo instead of here —
     see _gallery.blade.php. --}}
<div>
    <nav aria-label="{{ __('booking.nav_home') }}" class="flex flex-wrap items-center gap-1.5 text-xs text-ink-faint">
        <a href="{{ route('public.home') }}" class="hover:text-ink">{{ __('booking.nav_home') }}</a>
        <span aria-hidden="true">/</span>
        <a href="{{ route('public.vehicles') }}" class="hover:text-ink">{{ __('booking.nav_vehicles') }}</a>
        <span aria-hidden="true">/</span>
        <span class="font-semibold text-ink-muted">{{ $vehicle->name }}</span>
    </nav>

    <div class="mt-2 flex flex-wrap items-baseline justify-between gap-3">
        <h1 class="text-2xl font-bold text-ink sm:text-3xl">{{ $vehicle->name }}</h1>

        @if ($this->averageRating !== null)
            <span class="inline-flex items-center gap-1.5 text-sm text-ink-muted">
                <x-ui.stars :rating="$this->averageRating" />
                <span class="font-semibold text-ink">{{ number_format($this->averageRating, 1) }}</span>
                <span class="text-ink-faint">({{ trans_choice('booking.reviews_count', $this->reviews->count(), ['count' => $this->reviews->count()]) }})</span>
            </span>
        @endif
    </div>

    {{-- Wraps: four unbreakable spans in a row overflowed a 320px screen. --}}
    <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-ink-muted">
        <span>{{ $vehicle->year }}</span>
        <span>{{ __('booking.seats', ['count' => $vehicle->seats]) }}</span>
        <span>{{ $vehicle->fuel_type->getLabel() }}</span>
        <span>{{ $vehicle->transmission->getLabel() }}</span>
    </div>
</div>
