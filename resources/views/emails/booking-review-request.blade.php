<x-mail::message>
@php
    $tenant = $booking->vehicle->tenant ?? null;
    $logoUrl = $tenant?->logoUrlForEmail();
    $colorPrimary = $tenant?->colorPrimary() ?? config('branding.defaults.color_primary');
@endphp

@if($logoUrl)
<div style="text-align:center;margin-bottom:16px;">
<img src="{{ $logoUrl }}" alt="{{ $operator }}" style="max-height:60px;max-width:200px;">
</div>
@endif

<h1 style="color: {{ $colorPrimary }};">{{ $operator }}</h1>

{{ __('emails.review_request.greeting', ['name' => $booking->customer_name]) }}

{{ __('emails.review_request.intro', ['vehicle' => $booking->vehicle->name]) }}

@if(!empty($reviewUrl))
<x-mail::button :url="$reviewUrl">
{{ __('emails.review_request.button') }}
</x-mail::button>
@endif

{{ __('emails.review_request.outro', ['operator' => $operator]) }}
</x-mail::message>
