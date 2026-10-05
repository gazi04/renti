<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\MarketingPage;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the locale of a marketing page from its URL prefix (/sq, /en).
 *
 * The URL, not the session, decides the language here, so each language is a
 * separately crawlable page. The choice is still written to the session so the
 * unprefixed pages that follow (/signup, the `/` redirect) keep the language the
 * visitor was reading — the same thing the old POST /language toggle did.
 */
class SetMarketingLocale
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $locale): Response
    {
        $locale = MarketingPage::normalizeLocale($locale);

        app()->setLocale($locale);
        session(['locale' => $locale]);

        return $next($request);
    }
}
