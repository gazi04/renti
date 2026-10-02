@props([
    'tone' => 'brand',
])

@php
    $tones = [
        'brand' => 'bg-primary/10 text-secondary',
        'neutral' => 'bg-surface-sunken text-ink-muted',
        'positive' => 'bg-positive-surface text-positive',
        'critical' => 'bg-critical-surface text-critical',
        'notice' => 'bg-notice-surface text-notice',
        // For a badge overlaid on a photo of unknown colour — needs guaranteed
        // contrast rather than a semantic surface token.
        'surface' => 'bg-surface-raised/95 text-ink shadow-sm',
        'inverse' => 'bg-ink/85 text-ink-inverse',
    ];
@endphp

<span {{ $attributes->class([
    'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium',
    $tones[$tone] ?? $tones['brand'],
]) }}>
    {{ $slot }}
</span>
