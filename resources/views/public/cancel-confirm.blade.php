{{-- Adopts the storefront shell (branding, nav, footer, concierge) instead of
     the bespoke standalone document it used to be. This page runs inside an
     active tenant on a signed URL, so the visitor should stay in that operator's
     site rather than land somewhere unbranded mid-cancellation. --}}
<x-layouts::public :title="__('booking.confirm_cancel_heading')">
    <div class="mx-auto flex min-h-[60vh] w-full max-w-lg items-center px-4 py-8 sm:px-6 sm:py-12">
        <x-ui.card pad="lg" class="w-full text-center shadow-lg">
            <div class="mx-auto mb-4 flex size-14 items-center justify-center rounded-full bg-notice-surface text-notice">
                <flux:icon.exclamation-triangle class="size-7" />
            </div>

            <h1 class="mb-2 text-xl font-bold text-ink">{{ __('booking.confirm_cancel_heading') }}</h1>
            <p class="mb-1 text-sm text-ink-muted">{{ __('booking.confirm_cancel_body', ['tenant' => tenant()?->name ?? config('app.name')]) }}</p>
            <p class="mb-6 text-xs text-ink-faint">{{ __('booking.booking_reference') }}: {{ $booking->reference }}</p>

            <form method="POST" action="{{ $cancelUrl }}" class="flex flex-col items-center gap-3">
                @csrf
                <x-ui.button type="submit" variant="danger" class="w-full">
                    {{ __('booking.confirm_cancel_button') }}
                </x-ui.button>
                <a href="{{ route('public.home') }}"
                   class="inline-flex min-h-11 items-center text-sm text-ink-muted transition-colors hover:text-ink">
                    {{ __('booking.keep_booking') }}
                </a>
            </form>
        </x-ui.card>
    </div>
</x-layouts::public>
