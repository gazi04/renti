---
paths:
  - 'app/Providers/Filament/**'
---

# Filament

## Never add 'unsafe-inline' for the Filament panels — the panel shares an origin with the storefront
Filament ships NO CSP nonce support and emits raw inline <script> blocks (dark-mode bootstrap + its call + window.filamentData), which SecurityHeaders' `script-src 'self' 'unsafe-eval'` refuses. Do not "fix" that by relaxing script-src: the operator panel is operatorname.<domain>/dashboard, the SAME ORIGIN as that tenant's storefront, and SESSION_DOMAIN is parent-scoped, so inline script there is admin-session theft.

The refusal is cheap and deliberate: those blocks are pre-paint FOUC guards only. filament/filament/dist/index.js re-reads localStorage.theme at alpine:init and applies `.dark` via an Alpine.effect, so dark mode works without them. The pre-paint pass is done instead by resources/js/filament-theme-bootstrap.js, registered with `loadedOnRequest()` in AppServiceProvider and emitted at PanelsRenderHook::HEAD_END by both panel providers. It must stay a classic, non-deferred <script src> in <head> — async/defer/type="module" (so: any Vite entry) all run after first paint and bring the flash back. Run `php artisan filament:assets` and commit public/js/app/ after touching it.

Filament's six `@script`-wrapped blocks and x-data/x-load are NOT inline — Livewire runs them via `new Function`, i.e. 'unsafe-eval'. Don't count them as blocked.

Assert CSP with `cspDirective($csp, 'script-src')` from tests/Pest.php, never `toContain(...)` on the whole policy: substring assertions still pass once 'unsafe-inline' is appended to a directive, which is how this repo went without a working guard for as long as the CSP existed. See docs/summary.md, "Parent-scoped session cookie + load-bearing CSP".
