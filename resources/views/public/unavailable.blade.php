{{-- Deliberately NOT the storefront layout.

     This renders for pending, suspended and cancelled tenants. Giving it the
     public chrome would show nav links that immediately fail and a concierge
     widget for a business that is not trading — dishonest, and
     tests/Feature/TenantStatusGateTest.php asserts this page carries no
     "Powered by Renti" footer (that's earned by an active, live storefront —
     see the same test's opposite assertion for an active tenant). It stays a
     plain standalone document, and it deliberately does not reuse
     <x-ui.brand-mark> — that component links to the home page, which is
     exactly what's blocked here. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $name }} — {{ config('app.name') }}</title>

    @fonts(['poppins'])

    <style>:root { --font-family: 'Poppins', system-ui, sans-serif; } body { font-family: var(--font-family); }</style>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-dvh bg-surface text-ink antialiased">
    <main class="flex min-h-dvh items-center justify-center px-4 py-16 sm:px-6 lg:px-8">
        <div class="w-full max-w-md text-center">
            @php
                $message = match ($status) {
                    'pending' => __('This booking site is not live yet.'),
                    'suspended' => __('This booking site is temporarily unavailable. Please contact the business directly.'),
                    'cancelled' => __('This booking site is no longer available.'),
                    default => __('This booking site is currently unavailable.'),
                };

                $tone = match ($status) {
                    'pending' => ['bg-notice-surface', 'text-notice'],
                    'suspended', 'cancelled' => ['bg-critical-surface', 'text-critical'],
                    default => ['bg-surface-sunken', 'text-ink-faint'],
                };
            @endphp

            <span class="mx-auto mb-6 flex size-16 items-center justify-center rounded-full {{ $tone[0] }} {{ $tone[1] }}">
                @if ($status === 'pending')
                    <flux:icon.clock class="size-7" />
                @elseif (in_array($status, ['suspended', 'cancelled'], true))
                    <flux:icon.no-symbol class="size-7" />
                @else
                    <flux:icon.question-mark-circle class="size-7" />
                @endif
            </span>

            <h1 class="mb-2 text-xl font-bold text-ink">{{ $name }}</h1>
            <p class="text-sm text-ink-muted">{{ $message }}</p>
        </div>
    </main>
</body>
</html>
