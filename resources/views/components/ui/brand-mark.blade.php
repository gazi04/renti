@props([
    'onDark' => false,
])

<a href="{{ route('public.home') }}" {{ $attributes->class([
    'flex min-w-0 items-center gap-3 transition-opacity hover:opacity-80',
    $onDark ? 'text-ink-inverse' : 'text-ink',
]) }}>
    @if(tenant()?->logoUrl())
        <img src="{{ tenant()->logoUrl() }}" alt="{{ tenant()->name }}" class="h-9 w-auto max-w-[11rem] object-contain sm:max-w-[14rem]">
    @else
        <span class="flex size-10 shrink-0 items-center justify-center rounded-panel bg-primary text-on-primary">
            <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M5 13l4 4L19 7" />
            </svg>
        </span>
        <span class="truncate text-lg font-semibold">{{ tenant()?->name ?? config('app.name') }}</span>
    @endif
</a>
