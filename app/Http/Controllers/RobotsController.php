<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * robots.txt, served dynamically so the central host can advertise its sitemap.
 *
 * Every host keeps the policy the old static public/robots.txt gave it (allow
 * everything); only the central marketing host adds the Sitemap line. Do not
 * reintroduce a static public/robots.txt — the web server would serve it before
 * this route is ever reached.
 */
class RobotsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $body = "User-agent: *\nDisallow:\n";

        if ($request->getHost() === config('tenancy.central_domain')) {
            $body .= "\nSitemap: ".route('marketing.sitemap')."\n";
        }

        return response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
