<?php

use App\Enums\FuelType;
use App\Enums\Transmission;
use App\Enums\VehicleCategory;
use App\Enums\VehicleStatus;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.public')] #[Title('Browse Fleet')] class extends Component {
    use WithPagination;

    #[Url]
    public string $category = '';

    #[Url]
    public string $transmission = '';

    #[Url]
    public string $fuelType = '';

    #[Url]
    public string $seats = '';

    #[Url]
    public string $year = '';

    #[Url]
    public string $minPrice = '';

    #[Url]
    public string $maxPrice = '';

    #[Url]
    public string $search = '';

    #[Url(as: 'sort')]
    public string $sort = '';

    #[Url(as: 'start_date')]
    public string $startDate = '';

    #[Url(as: 'end_date')]
    public string $endDate = '';

    public function updatedCategory(): void { $this->resetPage(); }

    public function updatedTransmission(): void { $this->resetPage(); }

    public function updatedFuelType(): void { $this->resetPage(); }

    public function updatedSeats(): void { $this->resetPage(); }

    public function updatedYear(): void { $this->resetPage(); }

    public function updatedMinPrice(): void { $this->resetPage(); }

    public function updatedMaxPrice(): void { $this->resetPage(); }

    public function updatedSearch(): void { $this->resetPage(); }

    public function updatedSort(): void { $this->resetPage(); }

    #[On('listing-start-selected')]
    public function onListingStartSelected(string $date): void
    {
        $this->startDate = $date;
        $this->resetPage();
    }

    #[On('listing-end-selected')]
    public function onListingEndSelected(string $date): void
    {
        $this->endDate = $date;
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->category = '';
        $this->transmission = '';
        $this->fuelType = '';
        $this->seats = '';
        $this->year = '';
        $this->minPrice = '';
        $this->maxPrice = '';
        $this->search = '';
        $this->sort = '';
        $this->startDate = '';
        $this->endDate = '';
        $this->resetPage();
    }

    /**
     * The date-range filter is driven entirely by query-string input (either typed
     * directly or forwarded from another page), so it must never trust the raw
     * strings — a malformed value should just disable the filter, not 500.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private function parsedDateRange(): ?array
    {
        if ($this->startDate === '' || $this->endDate === '') {
            return null;
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->startDate) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->endDate)) {
            return null;
        }

        try {
            $start = CarbonImmutable::createFromFormat('Y-m-d', $this->startDate)->startOfDay();
            $end = CarbonImmutable::createFromFormat('Y-m-d', $this->endDate)->startOfDay();
        } catch (\Exception) {
            return null;
        }

        return $start->lt($end) ? [$start, $end] : null;
    }

    /**
     * Every filter except category, shared by vehicles() and categoryCounts()
     * so the two can never drift apart. Category is optional here because a
     * category's own sidebar count must not be narrowed by its own filter —
     * only by whichever OTHER filters are active.
     */
    private function filteredQuery(bool $includeCategory = true): Builder
    {
        return Vehicle::query()
            ->where('is_public', true)
            ->when($includeCategory && $this->category !== '', fn ($q) => $q->where('category', $this->category))
            ->when($this->transmission !== '', fn ($q) => $q->where('transmission', $this->transmission))
            ->when($this->fuelType !== '', fn ($q) => $q->where('fuel_type', $this->fuelType))
            ->when($this->seats !== '', fn ($q) => $q->where('seats', '>=', (int) $this->seats))
            ->when($this->year !== '', fn ($q) => $q->where('year', (int) $this->year))
            ->when($this->minPrice !== '', fn ($q) => $q->where('daily_rate', '>=', (float) $this->minPrice))
            ->when($this->maxPrice !== '', fn ($q) => $q->where('daily_rate', '<=', (float) $this->maxPrice))
            ->when($this->search !== '', fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($this->search).'%']))
            ->when($this->parsedDateRange(), fn ($q, array $range) => $q->availableBetween($range[0], $range[1]));
    }

    /**
     * Unavailable vehicles are listed, not hidden (backlog #3) — their page is
     * where the stock alert lives, so removing them from the fleet would leave
     * nothing to click. They sort last so the bookable fleet still leads, and
     * their cards say plainly that they cannot be booked. is_public still hides.
     */
    #[Computed]
    public function vehicles(): LengthAwarePaginator
    {
        return $this->filteredQuery()
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [VehicleStatus::Available->value])
            ->when($this->sort === 'price_asc', fn ($q) => $q->orderBy('daily_rate'))
            ->when($this->sort === 'price_desc', fn ($q) => $q->orderByDesc('daily_rate'))
            ->when($this->sort === '' || $this->sort === 'newest', fn ($q) => $q->latest())
            ->with('media')
            ->paginate(12);
    }

    /**
     * Per-category vehicle counts for the sidebar checklist, narrowed by every
     * OTHER active filter. Reads getRawOriginal() rather than the cast
     * attribute so the array key is the plain enum-backing string, not a
     * VehicleCategory instance (which can't be an array key).
     *
     * @return array<string, int>
     */
    #[Computed]
    public function categoryCounts(): array
    {
        return $this->filteredQuery(includeCategory: false)
            ->selectRaw('category, count(*) as aggregate')
            ->groupBy('category')
            ->get()
            ->mapWithKeys(fn (Vehicle $row) => [$row->getRawOriginal('category') => (int) $row->getAttribute('aggregate')])
            ->all();
    }

    /** Live "N vehicles available [for <range>]" line under the H1. */
    public function resultsSummary(): string
    {
        $count = $this->vehicles->total();
        $range = $this->parsedDateRange();

        if ($range === null) {
            return trans_choice('booking.filters_results_count', $count, ['count' => $count]);
        }

        return trans_choice('booking.filters_results_count_with_range', $count, [
            'count' => $count,
            'range' => $range[0]->format('j M').' – '.$range[1]->format('j M'),
        ]);
    }

    /** @return array<int, VehicleCategory> */
    #[Computed]
    public function categories(): array
    {
        return VehicleCategory::cases();
    }

    /** @return array<int, Transmission> */
    #[Computed]
    public function transmissions(): array
    {
        return Transmission::cases();
    }

    /** @return array<int, FuelType> */
    #[Computed]
    public function fuelTypes(): array
    {
        return FuelType::cases();
    }

    /** Seat-count thresholds offered in the filter — matches typical rental-fleet groupings. */
    #[Computed]
    public function seatOptions(): array
    {
        return [2, 4, 5, 7];
    }

    /** @return array<int, int> */
    #[Computed]
    public function years(): array
    {
        return Vehicle::query()
            ->where('is_public', true)
            ->whereNotNull('year')
            ->distinct()
            ->orderByDesc('year')
            ->pluck('year')
            ->all();
    }

}; ?>

<div>
{{-- Title band --}}
<div class="border-b border-line bg-surface-sunken">
    <x-ui.container class="py-8">
        <h1 class="text-2xl font-bold text-ink sm:text-3xl">{{ __('booking.browse_fleet') }}</h1>
        <p class="mt-1 text-sm text-ink-muted" wire:loading.class="opacity-60" wire:target="category,transmission,fuelType,seats,year,minPrice,maxPrice,search,sort">
            {{ $this->resultsSummary() }}
        </p>
    </x-ui.container>
</div>

<x-ui.container class="py-8 sm:py-12">
    {{-- Below md the filter panel collapses behind a toggle so the fleet itself
         is what a visitor sees first on a phone. x-show, not x-if: the inputs
         must stay in the DOM for the flatpickr instances in vehicle-filters.js
         to bind to #listing-start-picker / #listing-end-picker on load. --}}
    {{-- One instance only. Rendering the panel twice (a mobile copy and a
         desktop copy) would duplicate #listing-start-picker, and flatpickr binds
         by id — the second picker would silently never initialise. --}}
    <div x-data="{ open: false, desktop: window.matchMedia('(min-width: 768px)').matches }"
         x-init="window.matchMedia('(min-width: 768px)').addEventListener('change', e => desktop = e.matches)"
         class="md:flex md:items-start md:gap-10">
        <x-ui.button variant="secondary"
                     class="w-full md:hidden"
                     x-on:click="open = ! open"
                     x-bind:aria-expanded="open ? 'true' : 'false'"
                     aria-controls="fleet-filters">
            <flux:icon.adjustments-horizontal class="size-5" />
            {{ __('booking.filters_toggle') }}
        </x-ui.button>

        <div id="fleet-filters" class="mt-4 shrink-0 md:mt-0 md:w-64" x-show="open || desktop" x-cloak>
            @include('pages.public.partials.vehicles._filters')
        </div>

        <div class="mt-8 min-w-0 flex-1 md:mt-0">
            @if ($this->vehicles->isEmpty())
                <x-ui.empty-state icon="truck" :title="__('booking.no_vehicles')" />
            @else
                <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($this->vehicles as $vehicle)
                        <div wire:key="vehicle-{{ $vehicle->id }}">
                            @include('pages.public.partials.vehicles._card')
                        </div>
                    @endforeach
                </div>

                <div class="mt-8">
                    {{ $this->vehicles->links() }}
                </div>
            @endif
        </div>
    </div>
</x-ui.container>
</div>

@push('scripts')
    @vite('resources/js/vehicle-filters.js')
@endpush
