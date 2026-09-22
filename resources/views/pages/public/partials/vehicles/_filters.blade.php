@php
    /** Sidebar filter panel. Segmented pills/checklist wrap the same wire:model.live
     * properties the horizontal bar used to bind — only the markup shape changed. */
    $hasFilters = $category || $transmission || $fuelType || $seats !== '' || $year !== ''
        || $minPrice !== '' || $maxPrice !== '' || $search !== '' || $sort !== ''
        || $startDate !== '' || $endDate !== '';

    $pillClasses = 'flex min-h-9 grow basis-16 cursor-pointer items-center justify-center rounded-control border border-line-strong px-2 text-center text-xs font-semibold text-ink-muted transition-colors peer-checked:border-primary peer-checked:bg-primary/10 peer-checked:text-primary';
    $fieldClasses = 'w-full rounded-control border border-line-strong bg-surface-raised px-3 py-2 text-sm text-ink placeholder:text-ink-faint focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30';
    $groupLabelClasses = 'text-xs font-bold uppercase tracking-wide text-ink-faint';
@endphp

<div class="flex flex-col gap-6 rounded-panel border border-line bg-surface-raised p-4">
    <div class="flex items-center justify-between">
        <span class="flex items-center gap-2 text-sm font-bold text-ink">
            <flux:icon.adjustments-horizontal class="size-4" />
            {{ __('booking.filters_toggle') }}
        </span>
        @if ($hasFilters)
            <button wire:click="resetFilters" type="button" class="text-xs font-semibold text-primary hover:underline">
                {{ __('booking.filters_reset') }}
            </button>
        @endif
    </div>

    {{-- Category --}}
    <div class="flex flex-col gap-2">
        <span class="{{ $groupLabelClasses }}">{{ __('booking.filters_category') }}</span>
        @foreach ($this->categories as $cat)
            <label class="flex min-h-9 cursor-pointer items-center justify-between gap-2 text-sm text-ink">
                <span class="flex items-center gap-2">
                    <input type="radio" wire:model.live="category" value="{{ $cat->value }}"
                        class="size-4 shrink-0 appearance-none rounded border border-line-strong checked:border-primary checked:bg-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/30">
                    {{ $cat->getLabel() }}
                </span>
                <span class="text-xs text-ink-faint">{{ $this->categoryCounts[$cat->value] ?? 0 }}</span>
            </label>
        @endforeach
    </div>

    {{-- Transmission --}}
    <div class="flex flex-col gap-2">
        <span class="{{ $groupLabelClasses }}">{{ __('booking.filters_transmission') }}</span>
        <div class="flex flex-wrap gap-2">
            <label>
                <input type="radio" wire:model.live="transmission" value="" class="peer sr-only">
                <span class="{{ $pillClasses }}">{{ __('booking.filters_any') }}</span>
            </label>
            @foreach ($this->transmissions as $tr)
                <label>
                    <input type="radio" wire:model.live="transmission" value="{{ $tr->value }}" class="peer sr-only">
                    <span class="{{ $pillClasses }}">{{ $tr->getLabel() }}</span>
                </label>
            @endforeach
        </div>
    </div>

    {{-- Price per day --}}
    <div class="flex flex-col gap-2">
        <span class="{{ $groupLabelClasses }}">{{ __('booking.filters_price_per_day') }}</span>
        <div class="grid grid-cols-2 gap-2">
            <div>
                <label class="mb-1 block text-[11px] text-ink-faint">{{ __('booking.filters_price_min') }}</label>
                <input wire:model.live.debounce.300ms="minPrice" type="number" min="0" placeholder="{{ __('booking.currency_symbol') }}0" class="{{ $fieldClasses }}">
            </div>
            <div>
                <label class="mb-1 block text-[11px] text-ink-faint">{{ __('booking.filters_price_max') }}</label>
                <input wire:model.live.debounce.300ms="maxPrice" type="number" min="0" placeholder="{{ __('booking.currency_symbol') }}999" class="{{ $fieldClasses }}">
            </div>
        </div>
    </div>

    {{-- Seats --}}
    <div class="flex flex-col gap-2">
        <span class="{{ $groupLabelClasses }}">{{ __('booking.filters_seats') }}</span>
        <div class="flex flex-wrap gap-2">
            <label>
                <input type="radio" wire:model.live="seats" value="" class="peer sr-only">
                <span class="{{ $pillClasses }}">{{ __('booking.filters_any') }}</span>
            </label>
            @foreach ($this->seatOptions as $count)
                <label>
                    <input type="radio" wire:model.live="seats" value="{{ $count }}" class="peer sr-only">
                    <span class="{{ $pillClasses }}">{{ $count }}+</span>
                </label>
            @endforeach
        </div>
    </div>

    {{-- Fuel type --}}
    <div class="flex flex-col gap-2">
        <label class="{{ $groupLabelClasses }}">{{ __('booking.filters_fuel_type') }}</label>
        <select wire:model.live="fuelType" class="{{ $fieldClasses }}">
            <option value="">{{ __('booking.filters_any') }}</option>
            @foreach ($this->fuelTypes as $fuel)
                <option value="{{ $fuel->value }}">{{ $fuel->getLabel() }}</option>
            @endforeach
        </select>
    </div>

    {{-- Year --}}
    <div class="flex flex-col gap-2">
        <label class="{{ $groupLabelClasses }}">{{ __('booking.filters_year') }}</label>
        <select wire:model.live="year" class="{{ $fieldClasses }}">
            <option value="">{{ __('booking.filters_any') }}</option>
            @foreach ($this->years as $y)
                <option value="{{ $y }}">{{ $y }}</option>
            @endforeach
        </select>
    </div>

    {{-- Dates --}}
    <div class="flex flex-col gap-2">
        <label for="listing-start-picker" class="{{ $groupLabelClasses }}">{{ __('booking.filters_pickup_date') }}</label>
        {{-- Plain flatpickr instance (dd/mm/yyyy), no wire:model — reports back
             via a dispatched event, see resources/js/vehicle-filters.js. --}}
        <input id="listing-start-picker" type="text" placeholder="dd/mm/yyyy" class="{{ $fieldClasses }}">
    </div>

    <div class="flex flex-col gap-2">
        <label for="listing-end-picker" class="{{ $groupLabelClasses }}">{{ __('booking.filters_return_date') }}</label>
        <input id="listing-end-picker" type="text" placeholder="dd/mm/yyyy" class="{{ $fieldClasses }}">
    </div>

    {{-- Search --}}
    <div class="flex flex-col gap-2">
        <label class="{{ $groupLabelClasses }}">{{ __('booking.filters_search') }}</label>
        <input wire:model.live.debounce.300ms="search" type="text" placeholder="{{ __('booking.filters_search_placeholder') }}" class="{{ $fieldClasses }}">
    </div>

    {{-- Sort --}}
    <div class="flex flex-col gap-2">
        <label class="{{ $groupLabelClasses }}">{{ __('booking.filters_sort') }}</label>
        <select wire:model.live="sort" class="{{ $fieldClasses }}">
            <option value="newest">{{ __('booking.sort_newest') }}</option>
            <option value="price_asc">{{ __('booking.sort_price_asc') }}</option>
            <option value="price_desc">{{ __('booking.sort_price_desc') }}</option>
        </select>
    </div>

    {{-- Every filter is already wire:model.live — this just closes the mobile
         drawer, it does not "apply" anything that isn't already applied. --}}
    <x-ui.button type="button" x-on:click="open = false" class="w-full md:hidden">
        {{ __('booking.filters_apply') }}
    </x-ui.button>
</div>
