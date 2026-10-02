{{-- Home-only featured-vehicle card. Deliberately separate from
     partials/vehicles/_card.blade.php (the full listing page's card), which
     is out of scope for this redesign pass. --}}
@php
    $isBookable = $vehicle->status === \App\Enums\VehicleStatus::Available;
@endphp

<a href="{{ route('public.vehicle', $vehicle) }}"
   class="flex h-full flex-col overflow-hidden rounded-panel border border-line bg-surface-raised transition-shadow hover:shadow-md {{ $isBookable ? '' : 'opacity-75' }}">
    <div class="aspect-video overflow-hidden bg-gradient-to-br from-primary to-ink">
        @if ($vehicle->getFirstMedia('vehicle_photos'))
            <img src="{{ $vehicle->getFirstMediaUrl('vehicle_photos', 'web') }}"
                 alt="{{ $vehicle->name }}"
                 loading="lazy"
                 class="h-full w-full object-cover">
        @else
            <div class="flex h-full w-full items-center justify-center text-ink-inverse/55">
                <flux:icon.truck class="size-10" />
            </div>
        @endif
    </div>

    <div class="flex flex-1 flex-col gap-2 p-4">
        <h3 class="break-words text-sm font-semibold leading-tight text-ink">{{ $vehicle->name }}</h3>

        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-ink-faint">
            <span>{{ __('booking.seats', ['count' => $vehicle->seats]) }}</span>
            <span>{{ $vehicle->transmission->getLabel() }}</span>
            <span>{{ $vehicle->fuel_type->getLabel() }}</span>
        </div>

        @unless ($isBookable)
            <x-ui.badge tone="neutral" class="self-start">{{ __('booking.vehicle_unavailable_badge') }}</x-ui.badge>
        @endunless

        <div class="mt-auto flex items-center justify-between gap-2 pt-1">
            <x-ui.price :amount="$vehicle->daily_rate" :per="__('booking.per_day')" size="sm" />
            <span class="text-xs font-medium text-ink-muted">{{ __('booking.home_vehicle_view_details') }}</span>
        </div>
    </div>
</a>
