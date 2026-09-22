<x-mail::message>
@php
    $tenant = $booking->vehicle->tenant ?? null;
    $logoUrl = $tenant?->logoUrlForEmail();
    $colorPrimary = $tenant?->colorPrimary() ?? config('branding.defaults.color_primary');
    $paymentInstructions = $tenant?->setting('payment_instructions');
@endphp

@if($logoUrl)
<div style="text-align:center;margin-bottom:16px;">
<img src="{{ $logoUrl }}" alt="{{ $tenant->name }}" style="max-height:60px;max-width:200px;">
</div>
@endif

<h1 style="color: {{ $colorPrimary }};">{{ $tenant->name ?? config('app.name') }}</h1>

{{ __('emails.booking_confirmed.greeting', ['name' => $booking->customer_name]) }}

{{ $intro }}

| | |
|---|---|
| **{{ __('emails.booking_confirmed.reference_label') }}** | {{ $booking->reference }} |
| **{{ __('emails.booking_confirmed.vehicle_label') }}** | {{ $booking->vehicle->name }} |
| **{{ __('emails.booking_confirmed.dates_label') }}** | {{ $booking->start_date->format('d M Y') }} – {{ $booking->end_date->format('d M Y') }} |
| **{{ __('emails.booking_confirmed.total_label') }}** | €{{ number_format($booking->total, 2) }} |

{{ $paymentInstructions ?? __('emails.booking_confirmed.payment_note') }}

@if(!empty($agreementUrl))
<x-mail::button :url="$agreementUrl" color="primary">
{{ __('emails.booking_confirmed.agreement_button') }}
</x-mail::button>
@endif

@if(!empty($cancelUrl))
<x-mail::button :url="$cancelUrl">
{{ __('emails.booking_confirmed.cancel_action') }}
</x-mail::button>

{{ __('emails.booking_confirmed.cancel_note') }}
@endif

{{ $outro }}
</x-mail::message>
