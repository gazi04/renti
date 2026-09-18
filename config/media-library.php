<?php

declare(strict_types=1);

use App\Services\Media\TenantAwarePathGenerator;

return [
    /*
     * Scopes media files by tenant, then collection (tenants/{id}/vehicle_photos/{id}/,
     * tenants/{id}/logo/{id}/) instead of Spatie's default bare media-ID folder shared
     * by every tenant. Every other key falls back to the package's own defaults via
     * mergeConfigFrom.
     */
    'path_generator' => TenantAwarePathGenerator::class,

    /*
     * Why an explicit default rather than leaving this to Spatie's own
     * env('MEDIA_DISK', 'public') fallback: every disk-choosing call site in this app
     * (Vehicle/Tenant registerMediaCollections, VehicleForm/BrandingSettings file
     * uploads, SecurityHeaders' CSP img-src) reads this key and documents 'public' as
     * the default (.ai/rules/app.md). Declaring it here makes that contract visible in
     * the file that actually governs it instead of resting on an unstated vendor merge
     * a future Spatie upgrade could change without this repo noticing. 'local' is a
     * private, signed-URL-only disk (config/filesystems.php) — Spatie's
     * DefaultUrlGenerator always emits a plain unsigned /storage/{path} URL regardless
     * of disk, so anything other than a disk with visibility 'public' 404s every
     * vehicle photo and every tenant logo, storefront-wide.
     */
    'disk_name' => env('MEDIA_DISK', 'public'),
];
