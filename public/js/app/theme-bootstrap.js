/**
 * Pre-paint theme application for the Filament panels.
 *
 * Filament ships this as a raw inline <script> in <head>
 * (filament/filament/resources/views/components/layout/base.blade.php), and the
 * app's Content-Security-Policy carries no 'unsafe-inline' in script-src, so the
 * browser refuses it. That policy is deliberate and stays: the operator panel is
 * served from the same origin as the tenant's public storefront, and
 * SESSION_DOMAIN is parent-scoped, so script execution on that origin is
 * admin-session theft. See App\Http\Middleware\SecurityHeaders.
 *
 * Dark mode itself is not what the refusal breaks — filament/filament/dist/index.js
 * re-reads localStorage.theme at alpine:init and applies the class through an
 * Alpine.effect. What the refused block uniquely provides is the PRE-PAINT pass,
 * without which a dark-mode user gets a white flash on every page load. This file
 * is that pass, served from 'self', where the policy already allows it.
 *
 * It must stay a classic, non-deferred script loaded in <head>: that is what makes
 * the browser run it before the body is parsed, which is the whole point. A Vite
 * entry would be type="module" and therefore deferred, i.e. too late.
 */
;(() => {
    // Server-side value (Panel::getDefaultThemeMode()), passed as a data
    // attribute because this file is static and cannot be templated.
    const defaultThemeMode =
        document.currentScript?.dataset.defaultThemeMode ?? 'system'

    const applyTheme = () => {
        // localStorage.theme and the `dark` class on <html> are the same pair
        // Filament's own external bundle reads and writes, so this reads a
        // contract the panel is already coupled to rather than inventing one.
        // window.theme is assigned for parity with the refused block. Nothing in
        // Filament reads it, but tests/Browser/Panel/PanelCspTest.php does: it is
        // the only observable that separates "this file ran" from "Alpine got
        // there on its own a moment later". Keep it.
        const theme = (window.theme =
            localStorage.getItem('theme') ?? defaultThemeMode)

        document.documentElement.classList.toggle(
            'dark',
            theme === 'dark' ||
                (theme === 'system' &&
                    window.matchMedia('(prefers-color-scheme: dark)').matches),
        )
    }

    applyTheme()

    // wire:navigate swaps the document but keeps <html>, so a stale class would
    // otherwise survive the navigation. Filament's refused block hooks the same
    // event. Unlike that block this toggles rather than only adding, matching
    // what the external bundle's Alpine.effect settles on either way.
    document.addEventListener('livewire:navigated', applyTheme)
})()
