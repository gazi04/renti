{{-- Specifications: icon tiles for the real enum/column specs plus whatever
     operator custom fields are set, then two real-data lines (mileage,
     deposit) driven by actual columns — not the generic "comprehensive
     insurance / 24-7 roadside assistance" marketing claims a design mockup
     might show, since none of that is backed by any per-vehicle data and
     `mileage_limit` can make "unlimited mileage" an outright false claim for
     an operator who caps it. --}}
<div>
    <h2 class="mb-4 text-lg font-semibold text-ink">{{ __('booking.specs_heading') }}</h2>

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
        <div class="flex items-center gap-3 rounded-control bg-surface-sunken p-3">
            <flux:icon.tag class="size-5 shrink-0 text-primary" />
            <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-ink">{{ $vehicle->category->getLabel() }}</p>
                <p class="text-xs text-ink-faint">{{ __('booking.spec_category') }}</p>
            </div>
        </div>
        <div class="flex items-center gap-3 rounded-control bg-surface-sunken p-3">
            <flux:icon.users class="size-5 shrink-0 text-primary" />
            <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-ink">{{ $vehicle->seats }}</p>
                <p class="text-xs text-ink-faint">{{ __('booking.spec_seats') }}</p>
            </div>
        </div>
        <div class="flex items-center gap-3 rounded-control bg-surface-sunken p-3">
            <flux:icon.cog-6-tooth class="size-5 shrink-0 text-primary" />
            <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-ink">{{ $vehicle->transmission->getLabel() }}</p>
                <p class="text-xs text-ink-faint">{{ __('booking.spec_transmission') }}</p>
            </div>
        </div>
        <div class="flex items-center gap-3 rounded-control bg-surface-sunken p-3">
            <flux:icon.fire class="size-5 shrink-0 text-primary" />
            <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-ink">{{ $vehicle->fuel_type->getLabel() }}</p>
                <p class="text-xs text-ink-faint">{{ __('booking.spec_fuel') }}</p>
            </div>
        </div>

        @foreach ($vehicle->custom_fields ?? [] as $field)
            @if (($field['label'] ?? '') !== '' && ($field['value'] ?? '') !== '')
                <div class="flex items-center gap-3 rounded-control bg-surface-sunken p-3">
                    <flux:icon.sparkles class="size-5 shrink-0 text-primary" />
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold text-ink">{{ $field['value'] }}</p>
                        <p class="truncate text-xs text-ink-faint">{{ $field['label'] }}</p>
                    </div>
                </div>
            @endif
        @endforeach
    </div>

    <div class="mt-4 grid grid-cols-1 gap-x-6 gap-y-2 text-sm text-ink-muted sm:grid-cols-2">
        <div class="flex items-center gap-2">
            <flux:icon.map class="size-4 shrink-0 text-positive" />
            {{ $vehicle->mileage_limit
                ? __('booking.spec_mileage_limited', ['limit' => number_format((int) $vehicle->mileage_limit)])
                : __('booking.spec_mileage_unlimited') }}
        </div>
        <div class="flex items-center gap-2">
            <flux:icon.banknotes class="size-4 shrink-0 text-positive" />
            {{ (float) $vehicle->deposit > 0
                ? __('booking.spec_deposit_required', ['amount' => __('booking.currency_symbol').number_format((float) $vehicle->deposit, 2)])
                : __('booking.spec_deposit_none') }}
        </div>
    </div>
</div>
