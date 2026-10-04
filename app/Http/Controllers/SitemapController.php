<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\MarketingPage;
use Illuminate\Http\Response;

/**
 * sitemap.xml for the central marketing site: every page in every locale, each
 * entry listing its language alternates so Google pairs the translations.
 *
 * Tenant storefronts are deliberately absent — they live on their own hosts, and
 * a sitemap may only list URLs of the host it is served from.
 */
class SitemapController extends Controller
{
    public function __invoke(): Response
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .view('marketing.sitemap', ['pages' => MarketingPage::cases()])->render();

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
