<?php

use App\Models\Plan;

/*
 * The marketing homepage is the one page most visitors hit on a phone. A
 * component/HTTP test can assert markup but not layout — it cannot see the page
 * pan sideways. This pins the regression: a horizontal-scroll gutter at
 * 320–375px (caused by unclamped decoration + an oversized hero headline).
 */
it('has no horizontal overflow on a small phone', function () {
    Plan::factory()->create(['slug' => 'basic', 'name' => 'Basic', 'price' => 20, 'is_active' => true, 'is_public' => true, 'sort_order' => 1]);
    Plan::factory()->create(['slug' => 'standard', 'name' => 'Standard', 'price' => 40, 'is_active' => true, 'is_public' => true, 'sort_order' => 2]);

    $page = visit('/sq')->withHost(config('tenancy.central_domain'));

    foreach ([320, 375] as $width) {
        $page->resize($width, 720)->assertNoJavascriptErrors();

        $overflow = $page->script('document.documentElement.scrollWidth - document.documentElement.clientWidth');
        expect($overflow)->toBeLessThanOrEqual(1, "at {$width}px the page overflows horizontally by {$overflow}px");
    }
});
