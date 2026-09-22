<?php

use App\Enums\PlanFeature;
use App\Enums\VehicleStatus;
use App\Models\Review;
use App\Models\Vehicle;
use App\Services\PricingService;
use App\Services\WaitlistService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts.public')] #[Title('Vehicle Details')] class extends Component {
    /**
     * The vehicle this page is about, resolved by route binding in mount().
     *
     * Locked because mount()'s abort_unless($vehicle->is_public, 404) runs once,
     * and every /livewire/update after it carries this model as a key the browser
     * holds. Livewire already refuses to re-point it: the snapshot is
     * HMAC-checksummed, and hydrateForUpdate() takes a model's meta only from that
     * verified snapshot — so an `updates` entry naming another id is discarded in
     * silence. #[Locked] adds nothing to the guarantee; it states it here, where
     * the guard is, and turns the silent discard into
     * CannotUpdateLockedPropertyException so a regression is loud instead of
     * invisible.
     *
     * Do not reason about this as "the tenant scope would catch it anyway".
     * ModelSynth restores through Model::newQueryForRestoration(), which is
     * newQueryWithoutScopes() — BelongsToTenant is NOT applied to that query. The
     * checksum is the whole of the containment, and if it ever stopped covering
     * the key the reach would be every tenant, not just this one.
     * tests/Feature/Security/LivewireModelTamperingTest.php pins both mechanisms.
     */
    #[Locked]
    public Vehicle $vehicle;

    /**
     * Carried forward from the listing page's date-range filter (never read for
     * anything but forwarding) so the booking wizard's CTA can pass them along
     * in turn — see _rates.blade.php and vehicle-booking.blade.php's mount().
     */
    #[Url(as: 'start_date')]
    public string $startDate = '';

    #[Url(as: 'end_date')]
    public string $endDate = '';

    /**
     * Only is_public gates the page — status does not (backlog #3).
     *
     * An unavailable vehicle still has a real page, because that page is the only
     * place a visitor can ask to hear when it comes back. is_public stays absolute:
     * hiding a vehicle is a deliberate act and must keep meaning hidden, so there
     * is nothing to subscribe to. The booking page and the availability endpoint
     * keep the strict check — you can look, but you cannot book.
     */
    public function mount(Vehicle $vehicle): void
    {
        abort_unless($vehicle->is_public, 404);

        $this->vehicle = $vehicle;
    }

    #[Computed]
    public function isBookable(): bool
    {
        return $this->vehicle->status === VehicleStatus::Available;
    }

    /** @return array<int, array{web: string, thumb: string}> */
    #[Computed]
    public function photos(): array
    {
        return $this->vehicle->getMedia('vehicle_photos')
            ->map(fn ($media): array => [
                'web' => $media->getUrl('web'),
                'thumb' => $media->getUrl('thumb'),
            ])
            ->all();
    }

    /**
     * Approved reviews for this vehicle, newest first. Free on every plan so
     * accumulated reviews are always visible. Auto tenant-scoped via BelongsToTenant.
     *
     * @return Collection<int, Review>
     */
    #[Computed]
    public function reviews(): Collection
    {
        return Review::query()
            ->where('vehicle_id', $this->vehicle->id)
            ->where('is_approved', true)
            ->latest('submitted_at')
            ->get();
    }

    #[Computed]
    public function averageRating(): ?float
    {
        $reviews = $this->reviews;

        return $reviews->isNotEmpty() ? round((float) $reviews->avg('rating'), 1) : null;
    }

    /**
     * The date-range filter is driven entirely by query-string input forwarded
     * from the listing page, so it must never trust the raw strings — a
     * malformed value should just disable the preview, not 500. Same pattern as
     * vehicle-listing.blade.php's parsedDateRange().
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
     * A live quote for the exact dates carried forward from the listing page's
     * filter — null when no valid range is known, in which case the booking
     * card falls back to the flat rate table instead (see _rates.blade.php).
     * Reuses PricingService directly so the math can never drift from what the
     * booking wizard actually charges.
     *
     * @return array{rate_type: \App\Enums\RateType, subtotal: float, discount: float, total: float, deposit: float, days: int, start: CarbonImmutable, end: CarbonImmutable}|null
     */
    #[Computed]
    public function priceBreakdown(): ?array
    {
        $range = $this->parsedDateRange();

        if ($range === null) {
            return null;
        }

        [$start, $end] = $range;
        $pricing = resolve(PricingService::class);

        return [
            ...$pricing->calculate($this->vehicle, $start, $end),
            'days' => $pricing->rentalDays($start, $end),
            'start' => $start,
            'end' => $end,
        ];
    }

    // ── Waitlist (backlog #2) ───────────────────────────────────────────────────

    public string $waitlistStart = '';

    public string $waitlistEnd = '';

    public string $waitlistName = '';

    public string $waitlistEmail = '';

    public string $waitlistPhone = '';

    public bool $waitlistJoined = false;

    public ?string $waitlistError = null;

    /**
     * A waitlist is about dates, so it only makes sense while the vehicle is
     * actually on the road. Once it is off, dates are moot and the stock alert
     * takes over — the two panels are never shown together.
     */
    #[Computed]
    public function showsWaitlist(): bool
    {
        return $this->isBookable
            && (tenant()?->allowsFeature(PlanFeature::Waitlist)
                ?? (bool) PlanFeature::Waitlist->default());
    }

    /**
     * The date pickers are plain flatpickr instances (dd/mm/yyyy display,
     * matching the main booking calendar's picker) with no wire:model — they
     * report back via dispatched events instead, same pattern as
     * resources/js/booking-form.js's 'dates-selected'.
     */
    #[On('waitlist-start-selected')]
    public function onWaitlistStartSelected(string $date): void
    {
        $this->waitlistStart = $date;
    }

    #[On('waitlist-end-selected')]
    public function onWaitlistEndSelected(string $date): void
    {
        $this->waitlistEnd = $date;
    }

    /**
     * The booking calendar disables taken dates, so a visitor can never ask for
     * them — this panel is the only way to express that want, with its own
     * pickers where every date is selectable.
     */
    public function joinWaitlist(): void
    {
        // Re-assert the gate server-side. Unlike Filament — which re-checks
        // Page::canAccess() on every hydration and refuses to mount a hidden
        // action — a public Livewire SFC has no authorization hook, so this
        // method is directly callable over the wire and hiding the panel proves
        // nothing.
        abort_unless($this->showsWaitlist, 404);

        // Nothing else in this app throttles a public action, and this one takes
        // an email address and causes mail. Keyed per vehicle + IP.
        $key = 'waitlist-join:'.$this->vehicle->id.':'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, maxAttempts: 5)) {
            $this->waitlistError = __('booking.waitlist_throttled');

            return;
        }

        $this->validate([
            'waitlistStart' => ['required', 'date', 'after_or_equal:today'],
            'waitlistEnd' => ['required', 'date', 'after:waitlistStart'],
            'waitlistName' => ['required', 'string', 'max:255'],
            'waitlistEmail' => ['required', 'email', 'max:255'],
            'waitlistPhone' => ['nullable', 'string', 'max:50'],
        ]);

        RateLimiter::hit($key, decaySeconds: 3600);

        try {
            resolve(WaitlistService::class)->join($this->vehicle, [
                'name' => $this->waitlistName,
                'email' => $this->waitlistEmail,
                'phone' => $this->waitlistPhone ?: null,
                'start_date' => $this->waitlistStart,
                'end_date' => $this->waitlistEnd,
                'locale' => app()->getLocale(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Already waiting on this exact vehicle+dates. Show the same
            // thank-you rather than confirming the address is on the list.
        } catch (\InvalidArgumentException) {
            $this->waitlistError = __('booking.waitlist_invalid_dates');

            return;
        }

        $this->waitlistJoined = true;
        $this->waitlistError = null;
    }

    // ── Stock alert (backlog #3) ────────────────────────────────────────────────

    public string $stockAlertName = '';

    public string $stockAlertEmail = '';

    public string $stockAlertPhone = '';

    public bool $stockAlertJoined = false;

    public ?string $stockAlertError = null;

    #[Computed]
    public function showsStockAlert(): bool
    {
        return ! $this->isBookable
            && (tenant()?->allowsFeature(PlanFeature::StockAlert)
                ?? (bool) PlanFeature::StockAlert->default());
    }

    /**
     * Describes whichever notify panel applies, so one partial can render both.
     *
     * The waitlist and stock-alert panels were ~140 lines of near-identical
     * Blade. Only the wording, the icon, and whether dates are asked for ever
     * differed. The wire properties and methods stay separate and unrenamed —
     * WaitlistTest and StockAlertTest drive them directly by name.
     *
     * @return array{prefix: string, action: string, icon: string, withDates: bool, joined: bool, error: string|null}|null
     */
    #[Computed]
    public function notifyPanel(): ?array
    {
        return match (true) {
            $this->showsWaitlist => [
                'prefix' => 'waitlist',
                'action' => 'joinWaitlist',
                'icon' => 'calendar-days',
                'withDates' => true,
                'joined' => $this->waitlistJoined,
                'error' => $this->waitlistError,
            ],
            $this->showsStockAlert => [
                'prefix' => 'stockAlert',
                'action' => 'joinStockAlert',
                'icon' => 'bell',
                'withDates' => false,
                'joined' => $this->stockAlertJoined,
                'error' => $this->stockAlertError,
            ],
            default => null,
        };
    }

    /**
     * No dates asked for: the want here is the vehicle itself, whenever it returns.
     * That is what a null range means in waitlist_entries.
     */
    public function joinStockAlert(): void
    {
        // Re-assert the gate server-side. Unlike Filament — which re-checks
        // Page::canAccess() on every hydration and refuses to mount a hidden
        // action — a public Livewire SFC has no authorization hook, so this
        // method is directly callable over the wire and hiding the panel proves
        // nothing. This also covers a bookable vehicle, where the panel is gone
        // but the wire call would otherwise still land.
        abort_unless($this->showsStockAlert, 404);

        $key = 'stock-alert-join:'.$this->vehicle->id.':'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, maxAttempts: 5)) {
            $this->stockAlertError = __('booking.stock_alert_throttled');

            return;
        }

        $this->validate([
            'stockAlertName' => ['required', 'string', 'max:255'],
            'stockAlertEmail' => ['required', 'email', 'max:255'],
            'stockAlertPhone' => ['nullable', 'string', 'max:50'],
        ]);

        RateLimiter::hit($key, decaySeconds: 3600);

        try {
            resolve(WaitlistService::class)->joinStockAlert($this->vehicle, [
                'name' => $this->stockAlertName,
                'email' => $this->stockAlertEmail,
                'phone' => $this->stockAlertPhone ?: null,
                'locale' => app()->getLocale(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Already waiting on this vehicle. Show the same thank-you rather than
            // confirming the address is on the list.
        }

        $this->stockAlertJoined = true;
        $this->stockAlertError = null;
    }
}; ?>

<x-ui.container class="py-8 sm:py-12">
    @php
        $vehicle = $this->vehicle;
        $photos = $this->photos;
        $isBookable = $this->isBookable;
    @endphp

    <div class="space-y-8">
        @include('pages.public.partials.vehicle-show._header')

        {{-- DOM order is mobile order: gallery, then price + Book Now, then the
             detail. On lg the rates panel moves to a sticky right rail purely by
             grid placement, so there is no order-* juggling and no duplicated
             markup. Previously the price sat below the spec table on a phone. --}}
        <div class="grid gap-8 lg:grid-cols-3 lg:items-start">
            <div class="lg:col-span-2 lg:row-start-1">
                @include('pages.public.partials.vehicle-show._gallery')
            </div>

            <div class="lg:col-start-3 lg:row-start-1 lg:sticky lg:top-20">
                @include('pages.public.partials.vehicle-show._rates')
            </div>

            <div class="space-y-8 lg:col-span-2 lg:row-start-2">
                @include('pages.public.partials.vehicle-show._specs')
                @include('pages.public.partials.vehicle-show._description')
            </div>
        </div>
    </div>

    @include('pages.public.partials.vehicle-show._reviews')

    {{-- Stock alert (backlog #3) lives inside the booking card itself
         (_rates.blade.php) — the mockup's "sold out" card has no separate
         full-width section. Waitlist (#2) has no equivalent in that two-state
         card model — a bookable vehicle whose exact dates are taken still
         needs its own place to ask, so it stays here unchanged. --}}
    @if ($this->showsWaitlist)
        @include('pages.public.partials.vehicle-show._notify')
    @endif
</x-ui.container>
