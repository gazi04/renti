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

{{ __('emails.booking_cancelled.greeting', ['name' => $booking->customer_name]) }}

{{ $intro }}

| | |
|---|---|
| **{{ __('emails.booking_cancelled.reference_label') }}** | {{ $booking->reference }} |
| **{{ __('emails.booking_cancelled.vehicle_label') }}** | {{ $booking->vehicle->name }} |
| **{{ __('emails.booking_cancelled.dates_label') }}** | {{ $booking->start_date->format('d M Y') }} – {{ $booking->end_date->format('d M Y') }} |
@if($booking->cancellation_reason)
| **{{ __('emails.booking_cancelled.reason_label') }}** | {{ $booking->cancellation_reason }} |
@endif

{{ $outro }}
</x-mail::message>
