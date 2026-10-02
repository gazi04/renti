{{-- Sticky sidebar shared by all 3 booking-wizard steps: dates (once known),
     the live price preview, the promo-code field (step 1 only, same gating as
     before), and a step-conditional CTA. Replaces two different inline
     implementations (step 1's highlighted box, step 3's flat <dl>) with one
     consistent card — no property/method contract changed, only where the
     existing $priceBreakdown/$promoNotice/$promoError values get rendered. --}}
<x-ui.card class="lg:sticky lg:top-20">
    @if ($startDate !== '' && $endDate !== '')
        <div class="grid grid-cols-2 gap-3">
            <div class="rounded-control border border-line p-3">
                <p class="text-[11px] font-bold uppercase tracking-wide text-ink-faint">{{ __('booking.rates_pickup') }}</p>
                <p class="mt-1 text-sm font-semibold text-ink">{{ \Illuminate\Support\Facades\Date::parse($startDate)->translatedFormat('D, j M') }}</p>
            </div>
            <div class="rounded-control border border-line p-3">
                <p class="text-[11px] font-bold uppercase tracking-wide text-ink-faint">{{ __('booking.rates_return') }}</p>
                <p class="mt-1 text-sm font-semibold text-ink">{{ \Illuminate\Support\Facades\Date::parse($endDate)->translatedFormat('D, j M') }}</p>
            </div>
        </div>
    @endif

    @if ($priceBreakdown)
        <div class="{{ ($startDate !== '' && $endDate !== '') ? 'mt-5' : '' }}">
            <h3 class="mb-3 text-sm font-semibold text-ink">{{ __('booking.price_preview') }}</h3>
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between gap-4">
                    <dt class="text-ink-muted">{{ __('booking.rate_type') }}</dt>
                    <dd class="font-medium text-ink">{{ $priceBreakdown['rate_type'] }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-ink-muted">{{ __('booking.subtotal') }}</dt>
                    <dd><x-ui.price :amount="$priceBreakdown['subtotal']" size="sm" /></dd>
                </div>
                @if ($priceBreakdown['discount'] > 0)
                    <div class="flex justify-between gap-4 text-positive">
                        <dt>{{ __('booking.discount') }}</dt>
                        <dd>-{{ __('booking.currency_symbol') }}{{ number_format($priceBreakdown['discount'], 2) }}</dd>
                    </div>
                @endif
                <div class="flex justify-between gap-4 border-t border-line pt-2 text-base font-bold text-ink">
                    <dt>{{ __('booking.total') }}</dt>
                    <dd>{{ __('booking.currency_symbol') }}{{ number_format($priceBreakdown['total'], 2) }}</dd>
                </div>
                @if ($priceBreakdown['deposit'] > 0)
                    <div class="flex justify-between gap-4">
                        <dt class="text-ink-muted">{{ __('booking.deposit') }}</dt>
                        <dd><x-ui.price :amount="$priceBreakdown['deposit']" size="sm" /></dd>
                    </div>
                @endif
            </dl>
        </div>

        @if ($step === 1 && (tenant()?->allowsFeature(\App\Enums\PlanFeature::PromoCodes) ?? (bool) \App\Enums\PlanFeature::PromoCodes->default()))
            <x-ui.field :label="__('booking.promo_label')" class="mt-5">
                {{-- Stacks below sm: an input and a button side by side leave the
                     input unusably narrow on a phone. --}}
                <div class="flex flex-col gap-2 sm:flex-row">
                    <x-ui.input type="text"
                                wire:model="promoCode"
                                placeholder="{{ __('booking.promo_placeholder') }}"
                                class="uppercase sm:flex-1" />
                    <x-ui.button variant="secondary" wire:click="applyPromo">
                        {{ __('booking.promo_apply') }}
                    </x-ui.button>
                </div>
                @if ($promoNotice)
                    <p class="mt-1 text-sm text-positive">{{ $promoNotice }}</p>
                @elseif ($promoError)
                    <p class="mt-1 text-sm text-critical">{{ $promoError }}</p>
                @endif
            </x-ui.field>
        @endif
    @endif

    <div class="mt-6 flex flex-col gap-3">
        @if ($step === 1)
            <x-ui.button wire:click="nextStep" class="w-full">
                {{ __('booking.next') }}
            </x-ui.button>
        @elseif ($step === 2)
            <x-ui.button wire:click="nextStep" class="w-full">
                {{ __('booking.next') }}
            </x-ui.button>
            <button type="button" wire:click="prevStep" class="text-center text-sm font-semibold text-ink-muted hover:text-ink">
                {{ __('booking.back') }}
            </button>
        @else
            <x-ui.button wire:click="submit" wire:loading.attr="disabled" class="w-full">
                <span wire:loading.remove>{{ __('booking.confirm_booking') }}</span>
                <span wire:loading>…</span>
            </x-ui.button>
            <button type="button" wire:click="prevStep" class="text-center text-sm font-semibold text-ink-muted hover:text-ink">
                {{ __('booking.back') }}
            </button>
        @endif
    </div>
</x-ui.card>
