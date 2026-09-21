{{-- Four static, app-wide trust signals. Not operator-editable — every tenant
     on the platform gets the same baseline promise. --}}
<div class="grid grid-cols-2 gap-6 border-y border-line py-8 text-sm font-semibold text-ink-muted sm:grid-cols-4">
    <div class="flex items-center gap-2.5">
        <flux:icon.arrow-uturn-left class="size-5 shrink-0 text-primary" />
        {{ __('booking.home_trust_free_cancellation') }}
    </div>
    <div class="flex items-center gap-2.5">
        <flux:icon.check-circle class="size-5 shrink-0 text-primary" />
        {{ __('booking.home_trust_instant_confirmation') }}
    </div>
    <div class="flex items-center gap-2.5">
        <flux:icon.shield-check class="size-5 shrink-0 text-primary" />
        {{ __('booking.home_trust_comprehensive_insurance') }}
    </div>
    <div class="flex items-center gap-2.5">
        <flux:icon.lifebuoy class="size-5 shrink-0 text-primary" />
        {{ __('booking.home_trust_24_7_support') }}
    </div>
</div>
