{{-- Renders for EVERY 404 app-wide — unknown subdomain, a missing/private/
     wrong-tenant resource, an expired signed link, and every 403 (bootstrap/
     app.php rewrites all of them to 404 so a forbidden resource looks the
     same as a missing one). Uses the storefront shell like
     resources/views/public/cancel-confirm.blade.php does: <x-layouts::public>
     is null-safe on tenant() throughout, so this degrades to plain platform
     branding when no tenant is resolved (unknown subdomain, central domain)
     without any extra branching here. --}}
<x-layouts::public :title="__('booking.not_found_heading')">
    <div class="flex min-h-[70vh] items-center bg-gradient-to-b from-primary/5 to-surface">
        <x-ui.container class="flex flex-col items-center gap-4 py-16 text-center">
            <div class="relative flex items-center justify-center">
                <span class="text-[96px] leading-none font-extrabold tracking-tight text-line select-none sm:text-[128px]" aria-hidden="true">404</span>
                <span class="absolute flex size-20 items-center justify-center rounded-full bg-gradient-to-br from-primary to-[#4a5fff] shadow-lg shadow-primary/30">
                    <flux:icon.map class="size-9 text-on-primary" />
                </span>
            </div>

            <div class="flex gap-2.5" aria-hidden="true">
                @for ($i = 0; $i < 5; $i++)
                    <span class="h-[3px] w-[22px] rounded-full bg-line-strong"></span>
                @endfor
            </div>

            <h1 class="mt-1 text-2xl font-bold text-ink sm:text-3xl">{{ __('booking.not_found_heading') }}</h1>
            <p class="max-w-md text-sm leading-relaxed text-ink-muted">{{ __('booking.not_found_body') }}</p>

            <div class="mt-2 flex flex-wrap items-center justify-center gap-3">
                <x-ui.button :href="route('public.home')" variant="primary" size="lg">
                    {{ __('booking.not_found_back_home') }}
                </x-ui.button>
                <x-ui.button :href="route('public.vehicles')" variant="secondary" size="lg">
                    {{ __('booking.not_found_browse_vehicles') }}
                </x-ui.button>
            </div>

            <div class="mt-4 flex flex-wrap items-center justify-center gap-3 text-[13px] font-semibold text-ink-faint">
                <a href="{{ route('public.home') }}" class="transition-colors hover:text-ink">{{ __('booking.nav_home') }}</a>
                <span aria-hidden="true">·</span>
                <a href="{{ route('public.vehicles') }}" class="transition-colors hover:text-ink">{{ __('booking.nav_vehicles') }}</a>
                <span aria-hidden="true">·</span>
                <a href="{{ route('public.home') }}#about" class="transition-colors hover:text-ink">{{ __('booking.nav_about') }}</a>
                <span aria-hidden="true">·</span>
                <a href="{{ route('public.home') }}#contact" class="transition-colors hover:text-ink">{{ __('booking.nav_contact') }}</a>
            </div>
        </x-ui.container>
    </div>
</x-layouts::public>
