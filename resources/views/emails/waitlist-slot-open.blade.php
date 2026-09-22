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

{{ __('emails.waitlist_slot_open.greeting', ['name' => $entry->name]) }}

{{ __('emails.waitlist_slot_open.intro', [
    'vehicle' => $vehicle->name,
    'start' => $entry->start_date?->toDateString(),
    'end' => $entry->end_date?->toDateString(),
]) }}

@if(!empty($bookingUrl))
<x-mail::button :url="$bookingUrl">
{{ __('emails.waitlist_slot_open.button') }}
</x-mail::button>
@endif

{{-- Notifying does not hold the vehicle — say so, or the first person to be told
     will reasonably assume it is theirs. --}}
{{ __('emails.waitlist_slot_open.no_hold') }}

{{ __('emails.waitlist_slot_open.outro', ['operator' => $operator]) }}
</x-mail::message>
