<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    {{-- viewport-fit=cover lets the concierge launcher pad itself past the
         home indicator on notched phones via env(safe-area-inset-bottom). --}}
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ ($title ?? null) ? $title . ' — ' . (tenant()?->name ?? config('app.name')) : (tenant()?->name ?? config('app.name')) }}</title>

    @php
        $colorPrimary   = tenant()?->colorPrimary()   ?? config('branding.defaults.color_primary',   '#2e4bff');
        $colorSecondary = tenant()?->colorSecondary() ?? config('branding.defaults.color_secondary', '#1b32d8');
        $fontFamily     = tenant()?->setting('font_family',     config('branding.defaults.font_family',     'Poppins'));
        $fontAlias      = config('branding.fonts.' . $fontFamily . '.alias', 'inter');
    @endphp

    {{-- Self-hosted, and only the one face this tenant chose: @fonts() filters
         the build manifest by alias, so a Poppins tenant ships Poppins' two
         @font-face rules and one preload link — not all five families.
         $fontAlias comes from a curated config keyed by an allow-list-validated
         setting, so there is no injection path into this call. --}}
    @fonts([$fontAlias])

    {{-- Unlayered on purpose. Tailwind emits its @theme defaults inside
         `@layer theme`, and an unlayered declaration beats any layer regardless
         of source order — that is the whole mechanism by which a tenant's brand
         colour overrides the platform default. Never move this into a layer.
         tests/Feature/BrandingTest.php pins the literal `--color-primary: <hex>;`
         format of these two declarations. --}}
    <style>
        :root {
            --color-primary: {{ $colorPrimary }};
            --color-secondary: {{ $colorSecondary }};
            --font-family: '{{ $fontFamily }}', system-ui, sans-serif;
        }
        body { font-family: var(--font-family); }
        [x-cloak] { display: none !important; }
    </style>

    @vite(['resources/css/app.css'])
</head>
<body class="min-h-dvh overflow-x-clip bg-surface text-ink antialiased">

    <a href="#main"
       class="sr-only focus:not-sr-only focus:fixed focus:start-4 focus:top-4 focus:z-50 focus:rounded-control focus:bg-surface-raised focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-ink focus:shadow-lg focus:ring-2 focus:ring-primary">
        {{ __('booking.skip_to_content') }}
    </a>

    {{-- Public header --}}
    <header class="sticky top-0 z-40 border-b border-line bg-surface-raised" x-data="{ open: false }">
        <x-ui.container>
            <div class="flex h-16 items-center justify-between gap-4">
                <x-ui.brand-mark />

                {{-- Desktop nav --}}
                <nav class="hidden items-center gap-8 text-sm font-medium text-ink-muted md:flex">
                    <a href="{{ route('public.home') }}" class="text-ink transition-colors hover:text-primary">{{ __('booking.nav_home') }}</a>
                    <a href="{{ route('public.vehicles') }}" class="transition-colors hover:text-primary">{{ __('booking.nav_vehicles') }}</a>
                    <a href="{{ route('public.home') }}#about" class="transition-colors hover:text-primary">{{ __('booking.nav_about') }}</a>
                    <a href="#contact" class="transition-colors hover:text-primary">{{ __('booking.nav_contact') }}</a>
                </nav>

                <div class="flex shrink-0 items-center gap-3">
                    {{-- Language toggle: an explicit EN/SQ pill, not a single "flip"
                         toggle, so the active locale is always visibly stated. --}}
                    <div class="hidden items-center gap-0.5 rounded-full bg-surface-sunken p-0.5 text-xs font-semibold sm:flex">
                        @foreach(['en' => 'EN', 'sq' => 'SQ'] as $locale => $label)
                            <form method="POST" action="{{ route('public.language') }}">
                                @csrf
                                <input type="hidden" name="locale" value="{{ $locale }}">
                                <button type="submit"
                                        class="{{ app()->getLocale() === $locale ? 'bg-surface-raised text-ink shadow-sm' : 'text-ink-faint hover:text-ink' }} inline-flex min-h-9 items-center rounded-full px-3 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                                    {{ $label }}
                                </button>
                            </form>
                        @endforeach
                    </div>

                    {{-- Mobile hamburger. 44px hit area: the icon stays 24px, the
                         button around it does the work. --}}
                    <button type="button"
                            class="inline-flex size-11 items-center justify-center rounded-control text-ink-muted transition-colors hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary md:hidden"
                            @click="open = !open"
                            :aria-expanded="open ? 'true' : 'false'"
                            aria-controls="public-mobile-nav"
                            aria-label="{{ __('booking.nav_menu') }}">
                        <flux:icon.bars-3 x-show="!open" class="size-6" />
                        <flux:icon.x-mark x-show="open" x-cloak class="size-6" />
                    </button>
                </div>
            </div>
        </x-ui.container>

        {{-- Mobile nav --}}
        <nav id="public-mobile-nav" x-show="open" x-cloak class="border-t border-line bg-surface-raised md:hidden">
            <x-ui.container class="space-y-1 py-3 text-sm font-medium text-ink-muted">
                <a href="{{ route('public.home') }}" class="flex min-h-11 items-center rounded-control px-2 hover:bg-surface-sunken hover:text-ink">{{ __('booking.nav_home') }}</a>
                <a href="{{ route('public.vehicles') }}" class="flex min-h-11 items-center rounded-control px-2 hover:bg-surface-sunken hover:text-ink">{{ __('booking.nav_vehicles') }}</a>
                <a href="{{ route('public.home') }}#about" class="flex min-h-11 items-center rounded-control px-2 hover:bg-surface-sunken hover:text-ink" @click="open = false">{{ __('booking.nav_about') }}</a>
                <a href="#contact" class="flex min-h-11 items-center rounded-control px-2 hover:bg-surface-sunken hover:text-ink" @click="open = false">{{ __('booking.nav_contact') }}</a>

                <div class="flex items-center gap-0.5 rounded-full bg-surface-sunken p-0.5 text-xs font-semibold">
                    @foreach(['en' => 'EN', 'sq' => 'SQ'] as $locale => $label)
                        <form method="POST" action="{{ route('public.language') }}">
                            @csrf
                            <input type="hidden" name="locale" value="{{ $locale }}">
                            <button type="submit"
                                    class="{{ app()->getLocale() === $locale ? 'bg-surface-raised text-ink shadow-sm' : 'text-ink-faint hover:text-ink' }} inline-flex min-h-9 items-center rounded-full px-3 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                                {{ $label }}
                            </button>
                        </form>
                    @endforeach
                </div>
            </x-ui.container>
        </nav>
    </header>

    {{-- No container and no padding here on purpose. Each page opens its own
         <x-ui.container>, which lets a full-bleed section be a plain <section>
         with a background instead of the old `left-1/2 w-screen -translate-x-1/2`
         trick — that used 100vw, which includes the scrollbar gutter and caused
         horizontal scroll. pb-24 keeps the fixed concierge launcher from
         covering the last control on the page. --}}
    <main id="main" class="pb-24">
        {{ $slot }}
    </main>

    {{-- Footer --}}
    <footer id="contact" class="scroll-mt-20 border-t border-line">
        <div class="bg-ink text-ink-inverse">
            <x-ui.container class="grid gap-10 py-12 sm:grid-cols-2 lg:grid-cols-4">
                @php
                    $footerText       = tenant()?->localizedSetting('footer_text');
                    $socialFacebook   = tenant()?->socialFacebookUrl();
                    $socialInstagram  = tenant()?->socialInstagramUrl();
                    $contactPhone     = tenant()?->setting('contact_phone');
                    $contactEmail     = tenant()?->setting('contact_email');
                    $contactAddress   = tenant()?->setting('contact_address');
                @endphp

                {{-- Brand --}}
                <div class="lg:col-span-2">
                    <x-ui.brand-mark onDark />
                    @if($footerText)
                        <p class="mt-4 max-w-xs text-sm text-ink-inverse/70">{{ $footerText }}</p>
                    @endif

                    @if($socialFacebook || $socialInstagram)
                        <div class="mt-5 flex items-center gap-3">
                            @if($socialFacebook)
                                <a href="{{ $socialFacebook }}" target="_blank" rel="noopener noreferrer"
                                   class="inline-flex size-9 items-center justify-center rounded-full bg-ink-inverse/10 text-ink-inverse/80 transition-colors hover:bg-ink-inverse/20 hover:text-ink-inverse"
                                   aria-label="Facebook">
                                    <svg viewBox="0 0 24 24" class="size-4" fill="currentColor" aria-hidden="true">
                                        <path d="M13.5 21v-7.5h2.5l.4-3H13.5V8.5c0-.87.24-1.46 1.49-1.46H16.5V4.35c-.27-.04-1.18-.11-2.24-.11-2.22 0-3.74 1.35-3.74 3.84V10.5H8v3h2.52V21h2.98Z" />
                                    </svg>
                                </a>
                            @endif
                            @if($socialInstagram)
                                <a href="{{ $socialInstagram }}" target="_blank" rel="noopener noreferrer"
                                   class="inline-flex size-9 items-center justify-center rounded-full bg-ink-inverse/10 text-ink-inverse/80 transition-colors hover:bg-ink-inverse/20 hover:text-ink-inverse"
                                   aria-label="Instagram">
                                    <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                        <rect x="3.5" y="3.5" width="17" height="17" rx="4.5" />
                                        <circle cx="12" cy="12" r="3.8" />
                                        <circle cx="17" cy="7" r="0.9" fill="currentColor" stroke="none" />
                                    </svg>
                                </a>
                            @endif
                        </div>
                    @endif
                </div>

                {{-- Company links --}}
                <div>
                    <p class="text-xs font-semibold tracking-wide text-ink-inverse/50 uppercase">{{ __('booking.footer_company_heading') }}</p>
                    <nav class="mt-4 flex flex-col gap-2 text-sm text-ink-inverse/70">
                        <a href="{{ route('public.home') }}" class="min-h-8 hover:text-ink-inverse">{{ __('booking.nav_home') }}</a>
                        <a href="{{ route('public.vehicles') }}" class="min-h-8 hover:text-ink-inverse">{{ __('booking.nav_vehicles') }}</a>
                        <a href="{{ route('public.home') }}#about" class="min-h-8 hover:text-ink-inverse">{{ __('booking.nav_about') }}</a>
                        <a href="#contact" class="min-h-8 hover:text-ink-inverse">{{ __('booking.nav_contact') }}</a>
                    </nav>
                </div>

                {{-- Contact --}}
                @if($contactAddress || $contactPhone || $contactEmail)
                    <div>
                        <p class="text-xs font-semibold tracking-wide text-ink-inverse/50 uppercase">{{ __('booking.footer_contact_heading') }}</p>
                        <div class="mt-4 flex flex-col gap-3 text-sm text-ink-inverse/70">
                            @if($contactAddress)
                                <span class="flex items-start gap-2">
                                    <flux:icon.map-pin class="mt-0.5 size-4 shrink-0" />
                                    {{ $contactAddress }}
                                </span>
                            @endif
                            @if($contactPhone)
                                <a href="tel:{{ $contactPhone }}" class="flex min-h-8 items-center gap-2 hover:text-ink-inverse">
                                    <flux:icon.phone class="size-4 shrink-0" />
                                    {{ $contactPhone }}
                                </a>
                            @endif
                            @if($contactEmail)
                                <a href="mailto:{{ $contactEmail }}" class="flex min-h-8 items-center gap-2 hover:text-ink-inverse">
                                    <flux:icon.envelope class="size-4 shrink-0" />
                                    {{ $contactEmail }}
                                </a>
                            @endif
                        </div>
                    </div>
                @endif
            </x-ui.container>

            <div class="border-t border-ink-inverse/10">
                <x-ui.container class="py-4 text-center text-xs text-ink-inverse/50">
                    &copy; {{ date('Y') }} {{ tenant()?->name ?? config('app.name') }}. Powered by Renti.
                </x-ui.container>
            </div>
        </div>
    </footer>

    <livewire:faq-concierge />

    @stack('scripts')
</body>
</html>
