{{-- Booking card. Two top-level states:
     - bookable: price + "Available" pill, then either a live quote (when the
       listing page's date filter carried a valid start/end forward — see
       vehicle-show.blade.php's priceBreakdown()) or the flat multi-tier rate
       table when no date range is known yet.
     - not bookable: price (dimmed) + "Currently unavailable" pill, then either
       the stock alert form (#3) embedded compactly, or — when that plan
       feature is off — the plain notice. The booking page still 404s either
       way; this card is only ever a preview. --}}
<x-ui.card>
    @php
        $breakdown = $this->priceBreakdown;
        $rateDateParams = ($startDate ?? '') !== '' && ($endDate ?? '') !== ''
            ? ['start_date' => $startDate, 'end_date' => $endDate]
            : [];
    @endphp

    <div class="flex items-baseline justify-between gap-4">
        <x-ui.price :amount="$vehicle->daily_rate" :per="__('booking.per_day')" size="lg" :class="($isBookable ?? true) ? '' : 'opacity-60'" />

        @if ($isBookable ?? true)
            <x-ui.badge tone="positive">{{ __('booking.vehicle_available_badge') }}</x-ui.badge>
        @else
            <x-ui.badge tone="critical">{{ __('booking.vehicle_unavailable_badge') }}</x-ui.badge>
        @endif
    </div>

    @if ($isBookable ?? true)
        @if ($breakdown)
            <div class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div class="rounded-control border border-line p-3">
                    <p class="text-[11px] font-bold uppercase tracking-wide text-ink-faint">{{ __('booking.rates_pickup') }}</p>
                    <p class="mt-1 flex items-center gap-2 text-sm font-semibold text-ink">
                        <flux:icon.calendar-days class="size-4 shrink-0 text-primary" />
                        {{ $breakdown['start']->translatedFormat('D, j M') }}
                    </p>
                </div>
                <div class="rounded-control border border-line p-3">
                    <p class="text-[11px] font-bold uppercase tracking-wide text-ink-faint">{{ __('booking.rates_return') }}</p>
                    <p class="mt-1 flex items-center gap-2 text-sm font-semibold text-ink">
                        <flux:icon.calendar-days class="size-4 shrink-0 text-primary" />
                        {{ $breakdown['end']->translatedFormat('D, j M') }}
                    </p>
                </div>
            </div>

            <dl class="mt-4 space-y-2 text-sm">
                <div class="flex items-center justify-between gap-4">
                    <dt class="text-ink-muted">{{ __('booking.rates_breakdown_row', ['type' => $breakdown['rate_type']->getLabel(), 'count' => $breakdown['days']]) }}</dt>
                    <dd class="text-ink">{{ __('booking.currency_symbol') }}{{ number_format($breakdown['subtotal'], 2) }}</dd>
                </div>
                @if ($breakdown['discount'] > 0)
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-ink-muted">{{ __('booking.discount') }}</dt>
                        <dd class="text-positive">-{{ __('booking.currency_symbol') }}{{ number_format($breakdown['discount'], 2) }}</dd>
                    </div>
                @endif
                @if ($breakdown['deposit'] > 0)
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-ink-muted">{{ __('booking.deposit') }}</dt>
                        <dd class="text-ink">{{ __('booking.currency_symbol') }}{{ number_format($breakdown['deposit'], 2) }}</dd>
                    </div>
                @endif
                <div class="flex items-center justify-between gap-4 border-t border-line pt-2 text-base font-bold text-ink">
                    <dt>{{ __('booking.total') }}</dt>
                    <dd>{{ __('booking.currency_symbol') }}{{ number_format($breakdown['total'], 2) }}</dd>
                </div>
            </dl>
        @else
            <dl class="mt-5 space-y-2 text-sm">
                @if ($vehicle->hourly_rate)
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-ink-muted">{{ __('booking.per_hour') }}</dt>
                        <dd><x-ui.price :amount="$vehicle->hourly_rate" size="sm" /></dd>
                    </div>
                @endif
                @if ($vehicle->weekly_rate)
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-ink-muted">{{ __('booking.per_week') }}</dt>
                        <dd><x-ui.price :amount="$vehicle->weekly_rate" size="sm" /></dd>
                    </div>
                @endif
                @if ($vehicle->monthly_rate)
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-ink-muted">{{ __('booking.per_month') }}</dt>
                        <dd><x-ui.price :amount="$vehicle->monthly_rate" size="sm" /></dd>
                    </div>
                @endif
                @if ($vehicle->deposit)
                    <div class="mt-2 flex items-center justify-between gap-4 border-t border-line pt-2">
                        <dt class="text-ink-muted">{{ __('booking.deposit') }}</dt>
                        <dd><x-ui.price :amount="$vehicle->deposit" size="sm" /></dd>
                    </div>
                @endif
            </dl>
        @endif

        <x-ui.button size="lg"
                     class="mt-6 w-full"
                     :href="route('public.vehicle.book', $vehicle) . ($rateDateParams ? '?' . http_build_query($rateDateParams) : '')">
            {{ __('booking.book_now') }}
        </x-ui.button>

        @if ($breakdown)
            <p class="mt-2 text-center text-xs text-ink-faint">{{ __('booking.rates_no_payment_note') }}</p>
        @endif
    @else
        @if ($this->showsStockAlert)
            <div class="mt-5">
                @include('pages.public.partials.vehicle-show._notify', ['compact' => true])
            </div>
        @else
            <div class="mt-6 rounded-control bg-surface-sunken px-4 py-3 text-center text-sm font-medium text-ink-muted">
                {{ __('booking.vehicle_unavailable_notice') }}
            </div>
        @endif
    @endif
</x-ui.card>
