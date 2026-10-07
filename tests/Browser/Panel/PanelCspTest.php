<?php

declare(strict_types=1);

use App\Models\Tenant;

afterEach(function (): void {
    tenantHostReset();
    tenancy()->end();
});

/*
|--------------------------------------------------------------------------
| Operator panel: the CSP is enforced, and the panel survives it
|--------------------------------------------------------------------------
|
| A header assertion cannot tell a working panel from one whose scripts the
| browser refused to run — which is why the blocked-script bug  went unnoticed from the day the CSP
| was introduced. These drive a real Chromium instead.
|
| Nothing here logs in. /dashboard/login is inside the panel's route group, so
| it carries the panel middleware (and therefore the CSP) and renders the full
| Filament layout with its inline scripts. That sidesteps two hard blockers:
| Pest's withHost() does not survive a cross-host redirect, and phpunit.xml
| pins SESSION_DRIVER=array for the Browser suite, so no session would survive
| a second request anyway.
|
| Note what these cannot do: assertNoJavaScriptErrors() is blind to a CSP
| refusal. The plugin's init script patches console.log and listens for window
| 'error' events (InitScript.php:29-45); a refusal emits console.error and a
| securitypolicyviolation event, and the plugin subscribes to neither. Every
| assertion below is therefore a side effect, not an error.
|
*/

it('refuses Filament\'s inline scripts on the operator panel', function () {
    Tenant::factory()->withDomain('cspsub')->create();

    $page = visitAsTenant('cspsub', '/dashboard/login');

    // Asserting the server rendered it is what makes the next assertion mean
    // "the browser refused it" rather than "Filament stopped emitting it".
    expect($page->content())->toContain('window.filamentData = []');

    expect($page->script('typeof window.filamentData'))->toBe('undefined');
})->group('security');

it('applies the theme from a classic external head script', function () {
    Tenant::factory()->withDomain('cspdark')->create();

    $page = visitAsTenant('cspdark', '/dashboard/login');

    // Filament's pre-paint dark-mode bootstrap is one of the refused blocks, so
    // the same work is done by a self-hosted external asset that 'self' allows.
    //
    // This asserts the SHAPE, not the end state, and that is deliberate: the
    // external filament bundle re-applies the theme at alpine:init, so an
    // end-state assertion passes with or without any pre-paint script and
    // proves nothing. A classic, non-deferred <script src> in <head> is
    // guaranteed by the HTML spec to execute before the body is parsed — that
    // guarantee, not a timing measurement, is what rules out the flash.
    $shape = $page->script(<<<'JS'
        (() => {
            const tags = document.head.querySelectorAll('script[src*="theme-bootstrap"]');
            if (tags.length !== 1) {
                return { count: tags.length };
            }
            const tag = tags[0];
            return {
                count: 1,
                deferred: tag.hasAttribute('defer'),
                async: tag.hasAttribute('async'),
                module: tag.getAttribute('type') === 'module',
            };
        })()
    JS);

    expect($shape)->toBe([
        'count' => 1,
        'deferred' => false,
        'async' => false,
        'module' => false,
    ]);

    // And that it RAN. window.theme is assigned by exactly two things: Filament's
    // refused inline block, and the external asset that replaces it. The previous
    // test proves the inline one does not execute, so a string here is the
    // external one having done the work.
    expect($page->script('typeof window.theme'))->toBe('string');
})->group('security');

it('still ends up dark when a dark theme is saved', function () {
    Tenant::factory()->withDomain('cspend')->create();

    $page = visitAsTenant('cspend', '/dashboard/login');

    $page->script("localStorage.setItem('theme', 'dark')");
    $page->refresh();

    // End state, so this passes with or without the pre-paint script — Alpine
    // would get there on its own. Its job is to catch the bootstrap breaking
    // dark mode rather than fixing the flash, and to confirm the panel reaches
    // the right state with no inline script having executed at all.
    expect($page->script("document.documentElement.classList.contains('dark')"))->toBeTrue()
        ->and($page->script('typeof window.filamentData'))->toBe('undefined');
})->group('security');
