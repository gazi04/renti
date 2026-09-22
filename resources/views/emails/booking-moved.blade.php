<x-mail::message>
@php
    $tenant = $booking->vehicle->tenant ?? null;
    $logoUrl = $tenant?->logoUrlForEmail();
    $colorPrimary = $tenant?->colorPrimary() ?? config('branding.defaults.color_primary');
@endphp

@if($logoUrl)
<div style="text-align:center;margin-bottom:16px;">
<img src="{{ $logoUrl }}" alt="{{ $tenant->name }}" style="max-height:60px;max-width:200px;">
</div>
@endif

<h1 style="color: {{ $colorPrimary }};">{{ $tenant->name ?? config('app.name') }}</h1>

{{ __('emails.booking_moved.greeting', ['name' => $booking->customer_name]) }}

{{ __('emails.booking_moved.intro') }}

| | |
|---|---|
| **{{ __('emails.booking_moved.reference_label') }}** | {{ $booking->reference }} |
@if($booking->moved_at)
| **{{ __('emails.booking_moved.previous_label') }} — {{ __('emails.booking_moved.vehicle_label') }}** | {{ $booking->previousVehicle->name ?? '—' }} |
| **{{ __('emails.booking_moved.previous_label') }} — {{ __('emails.booking_moved.dates_label') }}** | {{ $booking->previous_start_date?->format('d M Y') }} – {{ $booking->previous_end_date?->format('d M Y') }} |
@endif
| **{{ __('emails.booking_moved.new_label') }} — {{ __('emails.booking_moved.vehicle_label') }}** | {{ $booking->vehicle->name }} |
| **{{ __('emails.booking_moved.new_label') }} — {{ __('emails.booking_moved.dates_label') }}** | {{ $booking->start_date->format('d M Y') }} – {{ $booking->end_date->format('d M Y') }} |

{{ __('emails.booking_moved.outro', ['operator' => $operator]) }}
</x-mail::message>
