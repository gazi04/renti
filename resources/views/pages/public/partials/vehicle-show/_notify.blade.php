@php
    /**
     * Shared "tell me when I can have this car" panel, driven by
     * vehicle-show.blade.php's notifyPanel() computed.
     *
     * Two flavours, never both at once:
     *   waitlist   — the car is bookable but the dates you want are taken (#2)
     *   stockAlert — the car is off the road entirely (#3), so no dates to ask for
     *
     * Both actions re-assert their gate server-side: a public Livewire method is
     * callable over the wire no matter what the page chose to render.
     *
     * Lang keys are parallel by design (booking.waitlist_* / booking.stock_alert_*),
     * so the prefix picks the wording.
     */
    $panel = $this->notifyPanel;
    $lang = 'booking.'.\Illuminate\Support\Str::snake($panel['prefix']);
    $prefix = $panel['prefix'];

    /**
     * True when embedded inside the vehicle-show booking card (the mockup's
     * "sold out" card state) rather than rendered as its own full-width
     * section — the card is too narrow for the 3-column name/email/phone row,
     * and its own border/shadow/padding already frame the content.
     */
    $compact = $compact ?? false;
@endphp

<div class="{{ $compact ? '' : 'mt-8' }}">
    <div class="{{ $compact ? '' : 'rounded-panel border border-line bg-surface-sunken p-5 shadow-sm sm:p-6' }}">
        @if ($panel['joined'])
            <div class="flex items-start gap-3">
                <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-positive-surface text-positive">
                    <flux:icon.check class="size-5" />
                </span>
                <div>
                    <h2 class="text-lg font-semibold text-ink">{{ __($lang.'_joined_heading') }}</h2>
                    <p class="mt-1 text-sm text-ink-muted">{{ __($lang.'_joined_body') }}</p>
                </div>
            </div>
        @else
            <div class="flex items-start gap-3">
                <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                    <flux:icon :icon="$panel['icon']" class="size-5" />
                </span>
                <div>
                    <h2 class="text-lg font-semibold text-ink">{{ __($lang.'_heading') }}</h2>
                    <p class="mt-1 text-sm text-ink-muted">{{ __($lang.'_intro') }}</p>
                </div>
            </div>

            @if ($panel['error'])
                <x-ui.alert tone="critical" class="mt-4">{{ $panel['error'] }}</x-ui.alert>
            @endif

            <form wire:submit="{{ $panel['action'] }}" class="mt-5 space-y-4">
                @if ($panel['withDates'])
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        {{-- Plain flatpickr instances (dd/mm/yyyy), no wire:model —
                             they report back via dispatched events, see
                             resources/js/waitlist-form.js. The ids are the contract. --}}
                        <x-ui.field :label="__($lang.'_start')" for="waitlist-start-picker" name="waitlistStart">
                            <x-ui.input id="waitlist-start-picker" type="text" placeholder="dd/mm/yyyy" />
                        </x-ui.field>
                        <x-ui.field :label="__($lang.'_end')" for="waitlist-end-picker" name="waitlistEnd">
                            <x-ui.input id="waitlist-end-picker" type="text" placeholder="dd/mm/yyyy" />
                        </x-ui.field>
                    </div>
                @endif

                <div class="grid grid-cols-1 gap-4 {{ $compact ? '' : 'sm:grid-cols-3' }}">
                    <x-ui.field :label="__($lang.'_name')" :for="$prefix.'-name'" :name="$prefix.'Name'">
                        <x-ui.input :id="$prefix.'-name'" type="text" wire:model="{{ $prefix }}Name" />
                    </x-ui.field>
                    <x-ui.field :label="__($lang.'_email')" :for="$prefix.'-email'" :name="$prefix.'Email'">
                        <x-ui.input :id="$prefix.'-email'" type="email" wire:model="{{ $prefix }}Email" />
                    </x-ui.field>
                    <x-ui.field :label="__($lang.'_phone')" :for="$prefix.'-phone'" :name="$prefix.'Phone'">
                        <x-ui.input :id="$prefix.'-phone'" type="text" wire:model="{{ $prefix }}Phone" />
                    </x-ui.field>
                </div>

                <div class="flex flex-wrap items-center gap-3 pt-1">
                    <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="{{ $panel['action'] }}">
                        <flux:icon.arrow-path wire:loading wire:target="{{ $panel['action'] }}" class="size-4 animate-spin" />
                        <flux:icon.bell wire:loading.remove wire:target="{{ $panel['action'] }}" class="size-4" />
                        {{ __($lang.'_submit') }}
                    </x-ui.button>

                    {{-- Being told first is a head start, not a hold. Say so here as
                         well as in the email, so nobody assumes the car is theirs. --}}
                    <span class="text-xs text-ink-muted">{{ __($lang.'_no_hold') }}</span>
                </div>
            </form>

            @if ($panel['withDates'])
                @push('scripts')
                    @vite('resources/js/waitlist-form.js')
                @endpush
            @endif
        @endif
    </div>
</div>
