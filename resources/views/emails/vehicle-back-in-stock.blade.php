<x-mail::message>
@php
    $tenant = $vehicle->tenant ?? null;
    $logoUrl = $tenant?->logoUrlForEmail();
    $colorPrimary = $tenant?->colorPrimary() ?? config('branding.defaults.color_primary');
@endphp

@if($logoUrl)
<div style="text-align:center;margin-bottom:16px;">
<img src="{{ $logoUrl }}" alt="{{ $operator }}" style="max-height:60px;max-width:200px;">
</div>
@endif

<h1 style="color: {{ $colorPrimary }};">{{ $operator }}</h1>

{{ __('emails.vehicle_back_in_stock.greeting', ['name' => $entry->name]) }}

{{ __('emails.vehicle_back_in_stock.intro', ['vehicle' => $vehicle->name]) }}

@if(!empty($vehicleUrl))
<x-mail::button :url="$vehicleUrl">
{{ __('emails.vehicle_back_in_stock.button') }}
</x-mail::button>
@endif

{{-- Notifying does not hold the vehicle — say so, or everyone told will
     reasonably assume it is theirs. Unlike the waitlist mail, this one goes to
     the whole list at once, so the point carries more weight here. --}}
{{ __('emails.vehicle_back_in_stock.no_hold') }}

{{ __('emails.vehicle_back_in_stock.outro', ['operator' => $operator]) }}
</x-mail::message>
