{{-- Adopts the storefront shell — see cancel-confirm.blade.php. --}}
<x-layouts::public :title="__('booking.booking_cancelled')">
    <div class="mx-auto flex min-h-[60vh] w-full max-w-lg items-center px-4 py-8 sm:px-6 sm:py-12">
        <x-ui.card pad="lg" class="w-full text-center shadow-lg">
            @if ($alreadyDone)
                <div class="mx-auto mb-4 flex size-14 items-center justify-center rounded-full bg-surface-sunken text-ink-muted">
                    <flux:icon.information-circle class="size-7" />
                </div>
                <h1 class="mb-2 text-xl font-bold text-ink">{{ __('booking.booking_already_cancelled') }}</h1>
            @elseif ($notCancellable)
                <div class="mx-auto mb-4 flex size-14 items-center justify-center rounded-full bg-notice-surface text-notice">
                    <flux:icon.information-circle class="size-7" />
                </div>
                <h1 class="mb-2 text-xl font-bold text-ink">{{ __('booking.booking_not_cancellable') }}</h1>
            @else
                <div class="mx-auto mb-4 flex size-14 items-center justify-center rounded-full bg-positive-surface text-positive">
                    <flux:icon.check class="size-7" />
                </div>
                <h1 class="mb-2 text-xl font-bold text-ink">{{ __('booking.booking_cancelled') }}</h1>
                <p class="mb-4 text-sm text-ink-muted">{{ __('booking.cancellation_confirmed') }}</p>
            @endif

            <p class="mb-6 text-xs text-ink-faint">{{ __('booking.booking_reference') }}: {{ $booking->reference }}</p>

            <x-ui.button :href="route('public.home')">
                {{ __('booking.back_to_fleet') }}
            </x-ui.button>
        </x-ui.card>
    </div>
</x-layouts::public>
