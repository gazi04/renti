<?php

use App\Enums\PlanFeature;
use App\Enums\VehicleStatus;
use App\Exceptions\CustomerNotEligibleException;
use App\Exceptions\InvalidBookingWindowException;
use App\Exceptions\PromoCodeInvalidException;
use App\Exceptions\VehicleNotAvailableException;
use App\Models\Customer;
use App\Models\PromoCode;
use App\Models\Vehicle;
use App\Support\PhoneNumber;
use Carbon\CarbonInterface;
use App\Services\BookingService;
use App\Services\PricingService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts.public')] #[Title('Book a Vehicle')] class extends Component {
    /**
     * Booking-submit throttle ceiling — shared between the cheap pre-check and the
     * authoritative post-increment check in submit(), so the two can't drift.
     */
    private const MAX_SUBMIT_ATTEMPTS = 5;

    /**
     * Tenant-wide companion to MAX_SUBMIT_ATTEMPTS. The per-vehicle cap alone
     * bounds nothing in aggregate — an attacker who cycles across the fleet gets
     * 5 fresh attempts per vehicle they target, which is exactly the
     * pending-booking inventory-denial threat #09 is about (see finding 01: a
     * Pending booking blocks the calendar until it expires). 20/hour is generous
     * for a real customer or family booking several different cars, while still
     * bounding total fleet-wide spam from one IP regardless of fleet size.
     */
    private const MAX_TENANT_SUBMIT_ATTEMPTS = 20;

    /**
     * Locked: mount()'s is_public + Available check runs once, and submit() books
     * whatever this property holds. Full rationale on vehicle-show.blade.php's
     * $vehicle.
     */
    #[Locked]
    public Vehicle $vehicle;

    public int $step = 1;

    // Step 1 — dates
    public string $startDate = '';

    public string $endDate = '';

    /** @var array<string, mixed>|null */
    public ?array $priceBreakdown = null;

    /**
     * Dates forwarded from the listing page's date-range filter (via the
     * vehicle-show page's CTA link) — read-only inputs, never written back to
     * the query string. mount() copies a valid pair into startDate/endDate so
     * the customer doesn't have to re-pick what they already chose upstream.
     */
    #[Url(as: 'start_date')]
    public string $prefillStartDate = '';

    #[Url(as: 'end_date')]
    public string $prefillEndDate = '';

    // Promo code (gated feature)
    public string $promoCode = '';

    public ?string $promoNotice = null;

    public ?string $promoError = null;

    // Step 2 — customer details
    public string $customerName = '';

    public string $customerPhone = '';

    public string $customerEmail = '';

    public string $pickupLocation = '';

    public string $notes = '';

    public bool $slotTaken = false;

    public ?string $submitError = null;

    /** Consent gate shown only on step 3 — required to submit, not to advance past steps 1–2. */
    public bool $termsAccepted = false;

    public function mount(Vehicle $vehicle): void
    {
        abort_unless($vehicle->is_public && $vehicle->status === VehicleStatus::Available, 404);
        $this->vehicle = $vehicle;

        if ($this->prefillStartDate !== '' && $this->prefillEndDate !== '') {
            try {
                $start = Date::parse($this->prefillStartDate);
                $end = Date::parse($this->prefillEndDate);
            } catch (\Exception) {
                return;
            }

            // A hand-edited ?start_date= is as untrusted as any other input: an
            // unbookable range is dropped rather than adopted, so the wizard
            // never opens on dates it would refuse at submit.
            if ($this->windowIsBookable($start, $end)) {
                $this->startDate = $this->prefillStartDate;
                $this->endDate = $this->prefillEndDate;
                $this->refreshPrice();
            }
        }
    }

    /**
     * Dispatched by resources/js/booking-form.js. A Livewire event is browser
     * input, so the pair is re-checked here — flatpickr's minDate/maxDate are
     * feedback, not a guard, and a forged event must not seed a price preview
     * for a window the server would refuse.
     */
    #[On('dates-selected')]
    public function onDatesSelected(string $start, string $end): void
    {
        $this->slotTaken = false;

        try {
            $parsedStart = Date::parse($start);
            $parsedEnd = Date::parse($end);
        } catch (\Exception) {
            return;
        }

        if (! $this->windowIsBookable($parsedStart, $parsedEnd)) {
            $this->priceBreakdown = null;

            return;
        }

        $this->startDate = $start;
        $this->endDate = $end;
        $this->refreshPrice();
    }

    public function nextStep(): void
    {
        $this->slotTaken = false;

        if ($this->step === 1) {
            $this->validate($this->dateRules(), $this->dateMessages());
        } elseif ($this->step === 2) {
            $this->validate([
                'customerName' => 'required|string|max:255',
                'customerPhone' => 'required|string|max:50',
                'customerEmail' => 'required|email|max:255',
                'pickupLocation' => 'nullable|string|max:255',
                'notes' => 'nullable|string|max:1000',
            ]);

            if ($this->promoCode !== '') {
                // The phone number just became known — previewPromo() can now
                // check per_customer_limit, which step 1 could never do. Catch
                // a promo that's stopped qualifying before the review screen,
                // not after the customer has filled in everything.
                $this->refreshPrice();

                if ($this->promoError !== null) {
                    $this->promoCode = '';
                    $this->promoError = __('booking.promo_removed_recalculated');
                }
            }
        }

        $this->step++;
    }

    public function prevStep(): void
    {
        $this->step = max(1, $this->step - 1);
    }

    public function applyPromo(): void
    {
        $this->refreshPrice();
    }

    public function submit(): void
    {
        // Highest-impact public action in the app (real row, vehicle-date lock,
        // operator email) — keyed per vehicle + IP, same pattern as waitlist/
        // stock-alert, plus a tenant-wide + IP companion so cycling across the
        // fleet can't multiply the per-vehicle budget (see MAX_TENANT_SUBMIT_ATTEMPTS).
        // Two gates per key, not one: the cheap pre-check below is a fast path
        // (skips validate() for an already-throttled visitor, keeps invalid
        // attempts free) but is NOT the security boundary — tooManyAttempts()
        // (read) and hit() (write) are separate calls, so two concurrent requests
        // can both read "under the limit" before either writes its increment.
        // The authoritative gate is the post-increment check further down: hit()
        // returns the count AFTER its atomic increment (Redis INCRBY, or a
        // lockForUpdate-wrapped transaction on the database store), so concurrent
        // callers get distinct, correctly-ordered counts and can't both slip past
        // it the way they could both slip past the pre-check.
        $vehicleKey = 'booking-submit:'.$this->vehicle->id.':'.request()->ip();
        $tenantKey = 'booking-submit-tenant:'.(tenant('id') ?? 'central').':'.request()->ip();

        if (
            RateLimiter::tooManyAttempts($vehicleKey, maxAttempts: self::MAX_SUBMIT_ATTEMPTS)
            || RateLimiter::tooManyAttempts($tenantKey, maxAttempts: self::MAX_TENANT_SUBMIT_ATTEMPTS)
        ) {
            $this->submitError = __('booking.submit_throttled');

            return;
        }

        $this->validate([
            ...$this->dateRules(),
            'customerName' => 'required|string|max:255',
            'customerPhone' => 'required|string|max:50',
            'customerEmail' => 'required|email|max:255',
            'pickupLocation' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
            'termsAccepted' => 'accepted',
        ], [...$this->dateMessages(), 'termsAccepted.accepted' => __('booking.terms_required')]);

        $this->slotTaken = false;

        // Hit both unconditionally (not short-circuited) so a request that trips
        // the vehicle cap doesn't leave the tenant-wide counter under-recorded,
        // and vice versa — the || below reads both post-increment counts either way.
        $vehicleHits = RateLimiter::hit($vehicleKey, decaySeconds: 3600);
        $tenantHits = RateLimiter::hit($tenantKey, decaySeconds: 3600);

        if ($vehicleHits > self::MAX_SUBMIT_ATTEMPTS || $tenantHits > self::MAX_TENANT_SUBMIT_ATTEMPTS) {
            $this->submitError = __('booking.submit_throttled');

            return;
        }

        try {
            $booking = resolve(BookingService::class)->create([
                'vehicle_id' => $this->vehicle->id,
                'start_date' => $this->startDate,
                'end_date' => $this->endDate,
                'customer_name' => $this->customerName,
                'customer_phone' => $this->customerPhone,
                'customer_email' => $this->customerEmail ?: null,
                'pickup_location' => $this->pickupLocation ?: null,
                'notes' => $this->notes ?: null,
                'promo_code' => $this->promoCode ?: null,
            ]);

            $this->redirect(route('public.booking.confirmation', $booking->reference), navigate: false);
        } catch (VehicleNotAvailableException) {
            $this->slotTaken = true;
            $this->step = 1;
            $this->startDate = '';
            $this->endDate = '';
            $this->priceBreakdown = null;
        } catch (InvalidBookingWindowException) {
            // A wizard left open across midnight, or forged dates. Back to step 1
            // for new dates — the customer's typed details are kept.
            $this->submitError = __('booking.date_window_invalid');
            $this->step = 1;
            $this->priceBreakdown = null;
        } catch (CustomerNotEligibleException) {
            // The operator has blacklisted this phone number. Deliberately a
            // generic failure: saying so would both confirm the flag to anyone
            // probing phone numbers and be gratuitously hostile to a customer
            // flagged by mistake. The operator can still book them by hand.
            $this->submitError = __('booking.submit_failed');
        } catch (PromoCodeInvalidException) {
            // The step-2 re-check already catches the common case; this is the
            // residual race (another booking consumed the last use in between).
            // Same recovery: drop the code, recompute without the discount, and
            // say plainly what happened rather than leaving a stale total.
            $this->promoCode = '';
            $this->refreshPrice();
            $this->promoError = __('booking.promo_removed_recalculated');
        }
    }

    /**
     * The one definition of a bookable window, shared by both validate() calls
     * so they cannot drift. The real guard is BookingService::lockAndValidate();
     * these rules exist to say so inline on step 1 instead of at the last click.
     *
     * @return array<string, array<int, string>>
     */
    private function dateRules(): array
    {
        $rules = [
            'startDate' => ['required', 'date', 'after_or_equal:today'],
            'endDate' => ['required', 'date', 'after:startDate'],
        ];

        if ($this->startDate !== '') {
            try {
                $latestEnd = Date::parse($this->startDate)->startOfDay()->addDays($this->maxRentalDays());
                $rules['endDate'][] = 'before_or_equal:'.$latestEnd->toDateString();
            } catch (\Exception) {
                // Unparseable start — the 'date' rule on startDate reports it.
            }
        }

        return $rules;
    }

    /** @return array<string, string> */
    private function dateMessages(): array
    {
        // The repo ships no lang/*/validation.php, so the framework defaults are
        // English-only — unacceptable on an Albanian-default storefront.
        return [
            'startDate.after_or_equal' => __('booking.date_in_past'),
            'endDate.before_or_equal' => __('booking.date_range_too_long', ['count' => $this->maxRentalDays()]),
        ];
    }

    private function maxRentalDays(): int
    {
        return Config::integer('bookings.max_rental_days');
    }

    /** Whether a parsed pair is one the server would accept — the untrusted-input gate. */
    private function windowIsBookable(CarbonInterface $start, CarbonInterface $end): bool
    {
        if (! $start->lt($end)) {
            return false;
        }

        if ($start->copy()->startOfDay()->lt(today())) {
            return false;
        }

        return resolve(PricingService::class)->rentalDays($start, $end) <= $this->maxRentalDays();
    }

    /**
     * Whether this visitor may still spend a preview lookup on "has THIS phone
     * number already redeemed this code?".
     *
     * That question is an oracle: the phone is whatever the visitor typed, so
     * repeated previews enumerate which numbers exist in the operator's customer
     * directory and which have used a given code. Budget it per tenant + IP, the
     * same house pattern submit() and the waitlist/stock-alert forms use.
     *
     * On exhaustion the caller degrades to isValidForCustomer(null) — exactly
     * what step 1 does before a phone is known — rather than erroring. The
     * authoritative per-customer check still runs under the row lock in
     * BookingService::resolvePromo(), so no discount is actually granted; the
     * visitor just sees the same optimistic preview a step-1 visitor sees, and
     * the existing promo_removed_recalculated recovery covers the rest.
     */
    private function mayProbeCustomerPromoHistory(): bool
    {
        $key = 'promo-preview:'.(tenant('id') ?? 'central').':'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, maxAttempts: 20)) {
            return false;
        }

        RateLimiter::hit($key, decaySeconds: 3600);

        return true;
    }

    /**
     * Resolve the entered promo code for the price preview (read-only — no lock,
     * no usage increment; the authoritative check + redemption happen in
     * BookingService at submit). Phone-aware once step 2 has set customerPhone,
     * so isValidForCustomer() can check per_customer_limit — the same rule
     * submit() enforces, via the same method, so the two can't drift. Before
     * that (step 1) the lookup finds no phone and the check is permissive,
     * matching what a preview can actually know. Sets promoNotice / promoError.
     */
    private function previewPromo(): ?PromoCode
    {
        $this->promoNotice = null;
        $this->promoError = null;

        $code = strtoupper(trim($this->promoCode));

        if ($code === '' || ! (tenant()?->allowsFeature(PlanFeature::PromoCodes) ?? (bool) PlanFeature::PromoCodes->default())) {
            return null;
        }

        $promo = PromoCode::query()->where('code', $code)->first();
        $customer = $this->customerPhone !== '' && $this->mayProbeCustomerPromoHistory()
            ? Customer::query()->where('phone', PhoneNumber::normalize($this->customerPhone))->first()
            : null;

        if ($promo === null || ! $promo->isValidForCustomer($customer)) {
            $this->promoError = __('booking.promo_invalid');

            return null;
        }

        $this->promoNotice = __('booking.promo_applied');

        return $promo;
    }

    private function refreshPrice(): void
    {
        if (! $this->startDate || ! $this->endDate) {
            $this->priceBreakdown = null;

            return;
        }

        $start = Date::parse($this->startDate);
        $end = Date::parse($this->endDate);

        if (! $start->lt($end)) {
            $this->priceBreakdown = null;

            return;
        }

        $pricing = resolve(PricingService::class)->calculate($this->vehicle, $start, $end, $this->previewPromo());

        $this->priceBreakdown = [
            'rate_type' => $pricing['rate_type']->getLabel(),
            'subtotal' => $pricing['subtotal'],
            'discount' => $pricing['discount'],
            'total' => $pricing['total'],
            'deposit' => $pricing['deposit'],
        ];
    }
}; ?>

<x-ui.container class="py-8 sm:py-12">
    {{-- Slot taken flash --}}
    @if ($slotTaken)
        <x-ui.alert tone="critical" class="mb-6">{{ __('booking.slot_taken') }}</x-ui.alert>
    @endif

    {{-- Vehicle summary.

         This block was the worst mobile bug on the site: a fixed w-32 image
         beside an unwrappable four-span spec row inside a bare `flex`, which
         forced the page to 446px on a 375px screen and clipped the text. It now
         stacks below sm, and the spec row wraps. --}}
    <div class="mb-8 flex flex-col gap-4 rounded-panel border border-line bg-surface-raised p-4 sm:flex-row sm:gap-6 sm:p-6">
        @if ($vehicle->getFirstMedia('vehicle_photos'))
            <div class="h-40 w-full shrink-0 overflow-hidden rounded-control bg-surface-sunken sm:h-24 sm:w-32">
                <img src="{{ $vehicle->getFirstMediaUrl('vehicle_photos', 'thumb') }}"
                     alt="{{ $vehicle->name }}" class="h-full w-full object-cover">
            </div>
        @endif

        <div class="min-w-0">
            <h1 class="text-xl font-bold text-ink">{{ $vehicle->name }}</h1>
            <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-ink-muted">
                <span>{{ $vehicle->category->getLabel() }}</span>
                <span>{{ __('booking.seats', ['count' => $vehicle->seats]) }}</span>
                <span>{{ $vehicle->fuel_type->getLabel() }}</span>
                <span>{{ $vehicle->transmission->getLabel() }}</span>
            </div>
            @php($vehicleDescription = $vehicle->descriptionFor())
            @if ($vehicleDescription !== '')
                <p class="mt-2 text-sm text-ink-muted">{{ $vehicleDescription }}</p>
            @endif
        </div>
    </div>

    {{-- Step indicators. Labels are hidden below sm — three of them plus two
         connectors cannot fit on a 320px screen, and the numbered circles plus
         the heading below already say where you are. --}}
    <ol class="mb-8 flex items-center gap-2">
        @foreach ([1 => __('booking.step_dates'), 2 => __('booking.step_details'), 3 => __('booking.step_review')] as $n => $label)
            <li class="flex items-center gap-2" @if ($step === $n) aria-current="step" @endif>
                <span @class([
                    'flex size-7 shrink-0 items-center justify-center rounded-full text-xs font-bold',
                    'bg-primary text-on-primary' => $step >= $n,
                    'bg-surface-sunken text-ink-faint' => $step < $n,
                ])>
                    @if ($step > $n)
                        <flux:icon.check class="size-3.5" />
                    @else
                        {{ $n }}
                    @endif
                </span>
                <span @class([
                    'hidden text-sm sm:inline',
                    'font-medium text-ink' => $step === $n,
                    'text-ink-faint' => $step !== $n,
                ])>{{ $label }}</span>
            </li>
            @if ($n < 3)
                <li class="mx-1 h-px flex-1 bg-line" aria-hidden="true"></li>
            @endif
        @endforeach
    </ol>

    <div class="lg:grid lg:grid-cols-3 lg:items-start lg:gap-8">
        <div class="lg:col-span-2">
            {{-- Step 1 — Dates --}}
            @if ($step === 1)
                <x-ui.card>
                    <h2 class="mb-4 text-lg font-semibold text-ink">{{ __('booking.pick_dates') }}</h2>

                    {{-- id and the three data-* attributes are the contract with
                         resources/js/booking-form.js and BookingWizardFlowTest. --}}
                    <x-ui.input id="date-range-picker"
                                type="text"
                                placeholder="{{ __('booking.date_placeholder') }}"
                                data-availability-url="{{ route('vehicle.availability', $vehicle) }}"
                                data-default-start="{{ $startDate }}"
                                data-default-end="{{ $endDate }}"
                                data-max-rental-days="{{ config('bookings.max_rental_days') }}" />
                    @error('startDate') <p class="mt-1 text-xs text-critical">{{ $message }}</p> @enderror
                    @error('endDate') <p class="mt-1 text-xs text-critical">{{ $message }}</p> @enderror
                </x-ui.card>

                @push('scripts')
                    @vite('resources/js/booking-form.js')
                @endpush
            @endif

            {{-- Step 2 — Customer details --}}
            @if ($step === 2)
                <x-ui.card>
                    <h2 class="mb-1 text-lg font-semibold text-ink">{{ __('booking.step_details') }}</h2>

                    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        {{-- data-test hooks are the contract with BookingWizardFlowTest. --}}
                        <x-ui.field :label="__('booking.customer_name')" name="customerName" required>
                            <x-ui.input wire:model="customerName" type="text" autocomplete="name" data-test="customer-name" />
                        </x-ui.field>

                        <x-ui.field :label="__('booking.customer_phone')" name="customerPhone" required>
                            <x-ui.input wire:model="customerPhone" type="tel" autocomplete="tel" data-test="customer-phone" />
                        </x-ui.field>

                        <x-ui.field :label="__('booking.customer_email')" name="customerEmail" required>
                            <x-ui.input wire:model="customerEmail" type="email" autocomplete="email" data-test="customer-email" />
                        </x-ui.field>

                        <x-ui.field :label="__('booking.pickup_location')" name="pickupLocation">
                            <x-ui.input wire:model="pickupLocation" type="text" />
                        </x-ui.field>

                        <div class="sm:col-span-2">
                            <x-ui.field :label="__('booking.notes')" name="notes">
                                <x-ui.textarea wire:model="notes" rows="3" />
                            </x-ui.field>
                        </div>
                    </div>
                </x-ui.card>
            @endif

            {{-- Step 3 — Review & submit --}}
            @if ($step === 3)
                <div class="flex flex-col gap-4">
                    <h2 class="text-lg font-semibold text-ink">{{ __('booking.review_heading') }}</h2>

                    <x-ui.card>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="text-sm font-bold text-ink">{{ __('booking.dates_location_heading') }}</span>
                            <button type="button" wire:click="$set('step', 1)" class="text-sm font-semibold text-primary hover:underline">
                                {{ __('booking.edit') }}
                            </button>
                        </div>
                        <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <p class="text-[11px] font-bold uppercase tracking-wide text-ink-faint">{{ __('booking.rates_pickup') }}</p>
                                <p class="mt-0.5 text-sm font-semibold text-ink">{{ \Illuminate\Support\Facades\Date::parse($startDate)->translatedFormat('D, j M') }}</p>
                            </div>
                            <div>
                                <p class="text-[11px] font-bold uppercase tracking-wide text-ink-faint">{{ __('booking.rates_return') }}</p>
                                <p class="mt-0.5 text-sm font-semibold text-ink">{{ \Illuminate\Support\Facades\Date::parse($endDate)->translatedFormat('D, j M') }}</p>
                            </div>
                        </div>
                    </x-ui.card>

                    <x-ui.card>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="text-sm font-bold text-ink">{{ __('booking.your_details') }}</span>
                            <button type="button" wire:click="$set('step', 2)" class="text-sm font-semibold text-primary hover:underline">
                                {{ __('booking.edit') }}
                            </button>
                        </div>
                        <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <p class="text-[11px] font-bold uppercase tracking-wide text-ink-faint">{{ __('booking.customer_name') }}</p>
                                <p class="mt-0.5 text-sm font-semibold text-ink">{{ $customerName }}</p>
                            </div>
                            <div>
                                <p class="text-[11px] font-bold uppercase tracking-wide text-ink-faint">{{ __('booking.customer_phone') }}</p>
                                <p class="mt-0.5 text-sm font-semibold text-ink">{{ $customerPhone }}</p>
                            </div>
                            @if ($customerEmail)
                                <div>
                                    <p class="text-[11px] font-bold uppercase tracking-wide text-ink-faint">{{ __('booking.customer_email') }}</p>
                                    <p class="mt-0.5 text-sm font-semibold text-ink">{{ $customerEmail }}</p>
                                </div>
                            @endif
                            @if ($pickupLocation)
                                <div>
                                    <p class="text-[11px] font-bold uppercase tracking-wide text-ink-faint">{{ __('booking.pickup_location') }}</p>
                                    <p class="mt-0.5 text-sm font-semibold text-ink">{{ $pickupLocation }}</p>
                                </div>
                            @endif
                        </div>
                    </x-ui.card>

                    <x-ui.card>
                        <div class="flex flex-col gap-1 text-sm sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                            <span class="text-ink-muted">{{ __('booking.payment_note') }}</span>
                            <span class="font-medium text-ink sm:text-right">{{ tenant()?->setting('payment_instructions', __('booking.payment_note_value')) }}</span>
                        </div>
                    </x-ui.card>

                    <x-ui.alert tone="notice" class="text-xs">{{ __('booking.pending_notice') }}</x-ui.alert>

                    @if ($promoError)
                        <x-ui.alert tone="critical">{{ $promoError }}</x-ui.alert>
                    @endif

                    @if ($submitError)
                        <x-ui.alert tone="critical">{{ $submitError }}</x-ui.alert>
                    @endif

                    <label class="flex items-start gap-3">
                        <input type="checkbox" wire:model="termsAccepted"
                               class="mt-0.5 size-[18px] shrink-0 appearance-none rounded border border-line-strong checked:border-primary checked:bg-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/30">
                        <span class="text-sm leading-relaxed text-ink-muted">{{ __('booking.terms_agreement') }}</span>
                    </label>
                    @error('termsAccepted') <p class="text-xs text-critical">{{ $message }}</p> @enderror
                </div>
            @endif
        </div>

        <div class="mt-8 lg:col-start-3 lg:mt-0">
            @include('pages.public.partials.vehicle-booking._summary-card')
        </div>
    </div>
</x-ui.container>
