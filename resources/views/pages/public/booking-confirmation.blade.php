<?php

use App\Models\Booking;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.public')] #[Title('Booking Received')] class extends Component {
    /**
     * Locked: the route's {booking:reference} is the only thing standing between a
     * visitor and someone else's booking details, and it guards the GET alone.
     * Full rationale on vehicle-show.blade.php's $vehicle.
     */
    #[Locked]
    public Booking $booking;

    public function mount(Booking $booking): void
    {
        $this->booking = $booking;
    }
};

?>

<div>
    @php
        $booking = $this->booking;
        $vehicle = $booking->vehicle;

        // All-day event(s) — the wizard only ever collects a date, never a time,
        // so an all-day ICS entry is the honest representation. DTEND is
        // exclusive per the spec, hence the +1 day on the return date.
        $icsLines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Renti//Booking//EN',
            'BEGIN:VEVENT',
            'UID:booking-'.$booking->id.'@'.request()->getHost(),
            'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z'),
            'DTSTART;VALUE=DATE:'.$booking->start_date->format('Ymd'),
            'DTEND;VALUE=DATE:'.$booking->end_date->copy()->addDay()->format('Ymd'),
            'SUMMARY:'.($vehicle?->name ?? __('booking.vehicle')).' — '.(tenant()?->name ?? config('app.name')),
        ];

        if ($booking->pickup_location) {
            $icsLines[] = 'LOCATION:'.str_replace(',', '\,', $booking->pickup_location);
        }

        $icsLines[] = 'END:VEVENT';
        $icsLines[] = 'END:VCALENDAR';

        $icsHref = 'data:text/calendar;charset=utf-8,'.rawurlencode(implode("\r\n", $icsLines));
    @endphp

    {{-- Hero: a full-width band, not nested inside <x-ui.container> — that
         component is `max-w-6xl mx-auto`, so negative margins on a child can
         only ever cancel ITS OWN padding, not the auto-centering gap around
         it. At any viewport wider than the container's max width the band
         would visibly stop short of the real edge. Same pattern as the
         listing page's title band. --}}
    <div class="bg-gradient-to-b from-positive-surface to-surface">
        <x-ui.container class="flex flex-col items-center gap-3 py-12 text-center">
            <span class="flex size-16 items-center justify-center rounded-full bg-positive shadow-lg shadow-positive/30">
                <flux:icon.check class="size-8 text-on-primary" />
            </span>
            <h1 class="text-2xl font-bold text-ink sm:text-3xl">{{ __('booking.booking_received') }}</h1>
            @if ($booking->customer_email)
                <p class="text-sm text-ink-muted">{{ __('booking.confirmation_email_copy', ['email' => $booking->customer_email]) }}</p>
            @endif
            <span class="rounded-full border border-line bg-surface-raised px-4 py-2 font-mono text-base font-bold text-primary shadow-sm">
                {{ $booking->reference }}
            </span>
        </x-ui.container>
    </div>

    <x-ui.container class="grid grid-cols-1 gap-8 py-8 sm:py-12 lg:grid-cols-2">
        {{-- Booking summary --}}
        <div class="flex flex-col gap-5">
            <x-ui.card class="flex items-center gap-4">
                <div class="h-16 w-20 shrink-0 overflow-hidden rounded-control bg-surface-sunken">
                    @if ($vehicle?->getFirstMedia('vehicle_photos'))
                        <img src="{{ $vehicle->getFirstMediaUrl('vehicle_photos', 'thumb') }}"
                             alt="{{ $vehicle->name }}" class="h-full w-full object-cover">
                    @else
                        <div class="flex h-full w-full items-center justify-center text-ink-faint">
                            <flux:icon.truck class="size-8" />
                        </div>
                    @endif
                </div>
                <div class="min-w-0">
                    <p class="truncate font-semibold text-ink">{{ $vehicle?->name }}</p>
                    @if ($vehicle)
                        <p class="text-xs text-ink-muted">
                            {{ $vehicle->category->getLabel() }} · {{ __('booking.seats', ['count' => $vehicle->seats]) }} · {{ $vehicle->transmission->getLabel() }} · {{ $vehicle->fuel_type->getLabel() }}
                        </p>
                    @endif
                </div>
            </x-ui.card>

            <x-ui.card class="flex flex-col gap-4">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <p class="text-[11px] font-bold uppercase tracking-wide text-ink-faint">{{ __('booking.rates_pickup') }}</p>
                        <p class="mt-0.5 text-sm font-semibold text-ink">{{ $booking->start_date->format('d M Y') }}</p>
                        @if ($booking->pickup_location)
                            <p class="text-xs text-ink-muted">{{ $booking->pickup_location }}</p>
                        @endif
                    </div>
                    <div>
                        <p class="text-[11px] font-bold uppercase tracking-wide text-ink-faint">{{ __('booking.rates_return') }}</p>
                        <p class="mt-0.5 text-sm font-semibold text-ink">{{ $booking->end_date->format('d M Y') }}</p>
                        @if ($booking->pickup_location)
                            <p class="text-xs text-ink-muted">{{ $booking->pickup_location }}</p>
                        @endif
                    </div>
                </div>

                <div class="h-px bg-line"></div>

                <div class="flex items-center justify-between text-sm">
                    <span class="text-ink-muted">{{ __('booking.confirmation_driver') }}</span>
                    <span class="font-semibold text-ink">{{ $booking->customer_name }}</span>
                </div>
                <div class="flex items-center justify-between text-sm">
                    <span class="text-ink-muted">{{ __('booking.total') }}</span>
                    <x-ui.price :amount="$booking->total" size="sm" class="font-bold" />
                </div>
            </x-ui.card>

            <a href="{{ $icsHref }}" download="{{ $booking->reference }}.ics"
               class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control border border-line-strong bg-surface-raised px-4 text-sm font-semibold text-ink transition-colors hover:bg-surface-sunken">
                <flux:icon.calendar-days class="size-4" />
                {{ __('booking.confirmation_add_to_calendar') }}
            </a>

            <p class="text-xs text-ink-faint">{{ __('booking.confirmation_agreement_pending') }}</p>

            <p class="text-sm text-ink-muted">
                @if ($booking->customer_email)
                    {{ __('booking.check_email_to_cancel') }}
                @else
                    {{ __('booking.contact_to_cancel') }}
                @endif
            </p>
        </div>

        {{-- What happens next --}}
        <x-ui.card class="flex flex-col gap-5">
            <span class="text-sm font-bold text-ink">{{ __('booking.confirmation_next_heading') }}</span>

            <div class="flex gap-3">
                <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-positive-surface text-positive">
                    <flux:icon.envelope class="size-4" />
                </span>
                <div>
                    <p class="text-sm font-semibold text-ink">{{ __('booking.confirmation_step_received_title') }}</p>
                    <p class="mt-0.5 text-xs text-ink-muted">{{ __('booking.confirmation_step_received_body') }}</p>
                </div>
            </div>

            <div class="flex gap-3">
                <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                    <flux:icon.clock class="size-4" />
                </span>
                <div>
                    <p class="text-sm font-semibold text-ink">{{ __('booking.confirmation_step_pending_title') }}</p>
                    <p class="mt-0.5 text-xs text-ink-muted">{{ __('booking.confirmation_step_pending_body') }}</p>
                </div>
            </div>

            <div class="flex gap-3">
                <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-notice-surface text-notice">
                    <flux:icon.identification class="size-4" />
                </span>
                <div>
                    <p class="text-sm font-semibold text-ink">{{ __('booking.confirmation_step_pickup_title') }}</p>
                    <p class="mt-0.5 text-xs text-ink-muted">{{ __('booking.confirmation_step_pickup_body') }}</p>
                </div>
            </div>

            <div class="h-px bg-line"></div>

            <p class="text-sm text-ink-muted">{{ tenant()?->setting('payment_instructions', __('booking.payment_note_value')) }}</p>

            <x-ui.button :href="route('public.home')" class="w-full">
                {{ __('booking.back_to_fleet') }}
            </x-ui.button>
        </x-ui.card>
    </x-ui.container>
</div>
