<?php

declare(strict_types=1);

use App\Models\Tenant;
use Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper;
use Stancl\Tenancy\Database\Models\Domain;
use Stancl\Tenancy\Features\UniversalRoutes;
use Stancl\Tenancy\TenantDatabaseManagers\MySQLDatabaseManager;
use Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLDatabaseManager;
use Stancl\Tenancy\TenantDatabaseManagers\SQLiteDatabaseManager;

return [
    'tenant_model' => Tenant::class,
    // No custom ID generator: tenants.id is a normal bigint auto-increment PK
    'id_generator' => null,

    'domain_model' => Domain::class,

    /**
     * The list of domains hosting your central app.
     *
     * Only relevant if you're using the domain or subdomain identification middleware.
     *
     * The local hosts are hard-coded; production hosts come from the same env
     * keys the rest of tenancy config reads (CENTRAL_DOMAIN / ADMIN_PANEL_DOMAIN),
     * so a new deployment only needs those set — not a code change here.
     */
    'central_domains' => array_values(array_unique(array_filter([
        '127.0.0.1',
        'lvh.me',
        'admin.lvh.me',
        /* 'localhost', */
        /* 'admin.localhost', */
        env('CENTRAL_DOMAIN', 'localhost'),
        env('ADMIN_PANEL_DOMAIN', 'admin.localhost'),
    ], static fn (mixed $host): bool => is_string($host) && $host !== ''))),

    /**
     * Base domain that operator subdomains are built on. A tenant created with
     * subdomain "ardi" resolves at "ardi.<tenant_base_domain>". Locally this is
     * "localhost" (so ardi.localhost works); production overrides via env.
     */
    'tenant_base_domain' => env('TENANT_BASE_DOMAIN', 'localhost'),

    /**
     * How long a signup may sit pending (or cancelled) before it counts as
     * abandoned. Subdomains are globally unique and are held for as long as the
     * tenant row exists, so abandoned signups need a release path.
     *
     * Two consumers, and the difference between them matters:
     *  - TenantsTable::isAbandoned() — when the admin panel's manual Purge action
     *    appears. Broad: pending or cancelled, past this window, no bookings.
     *  - Tenant::autoPurgeable() — what the daily tenants:purge-abandoned sweep
     *    DELETES unattended. A strict subset, narrowed to signups no human
     *    judgement is owed on. Raising this value delays real deletions.
     */
    'abandoned_after_days' => (int) env('TENANT_ABANDONED_AFTER_DAYS', 30),

    /**
     * Subdomains an operator may never claim. Read by App\Rules\AvailableSubdomain,
     * which is the single validator behind BOTH entry points (operator self-signup
     * and the admin TenantForm) — keeping the list here is what stops those two
     * from drifting apart, as they had.
     *
     * Three reasons a name is on this list:
     *   - it already resolves to platform infrastructure ("admin", "www", "api");
     *   - it would make a convincing phishing host for an operator to own
     *     ("login", "secure", "billing", "pay", "verify") — remember the session
     *     cookie is scoped to the parent domain, so a subdomain looks first-party;
     *   - it is a name the platform is likely to want later ("pulse", "status",
     *     "cdn", "horizon"). Reserving early is free; reclaiming a live operator's
     *     subdomain is not.
     *
     * Note "pulse" is currently served at <admin_domain>/pulse (a path, see
     * config/pulse.php), so it is not a live collision today — it is reserved
     * against the day the dashboard moves to its own host.
     */
    'reserved_subdomains' => [
        'account', 'admin', 'api', 'app', 'assets', 'auth',
        'billing', 'blog', 'cdn', 'dashboard', 'dev', 'docs',
        'ftp', 'help', 'horizon', 'login', 'mail', 'mx',
        'ns1', 'ns2', 'pay', 'payment', 'payments', 'pulse',
        'register', 'secure', 'signup', 'smtp', 'staff', 'static',
        'status', 'support', 'telescope', 'test', 'verify', 'webhooks',
        'webmail', 'www',
    ],

    /**
     * Platform-wide ceiling on operator self-signups per hour.
     *
     * The per-IP and per-email limiters on the signup page stop the ordinary
     * abuser; neither stops someone with a pool of proxies and fresh addresses
     * from mass-creating pending tenants, each squatting a subdomain until an
     * admin purges it. This is the floor under both.
     *
     * Env-tunable on purpose: a launch or a press mention is exactly when a fixed
     * cap would bite, and raising it should not need a deploy.
     */
    'signup_hourly_cap' => (int) env('TENANT_SIGNUP_HOURLY_CAP', 20),

    /**
     * Host the Super Admin (Filament) panel is served from. A central domain.
     * Locally "admin.localhost"; production overrides via env (e.g. admin.yourdomain.com).
     */
    'admin_domain' => env('ADMIN_PANEL_DOMAIN', 'admin.localhost'),

    /**
     * Canonical central (marketing) host. Central web routes that share a URI with
     * a tenant panel — e.g. the starter "/dashboard" vs the operator panel at
     * "/dashboard" on subdomains — are pinned to this host so they don't collide.
     */
    'central_domain' => env('CENTRAL_DOMAIN', 'localhost'),

    /**
     * Tenancy bootstrappers are executed when tenancy is initialized.
     * Their responsibility is making Laravel features tenant-aware.
     *
     * To configure their behavior, see the config keys below.
     */
    'bootstrappers' => [
        // Single-database tenancy: DatabaseTenancyBootstrapper is intentionally disabled.
        // All tenants share one database and are scoped by tenant_id via the BelongsToTenant trait.
        // Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper::class,
        CacheTenancyBootstrapper::class,
        FilesystemTenancyBootstrapper::class,
        QueueTenancyBootstrapper::class,
        // Stancl\Tenancy\Bootstrappers\RedisTenancyBootstrapper::class, // Note: phpredis is needed
    ],

    /**
     * Database tenancy config. Used by DatabaseTenancyBootstrapper.
     */
    'database' => [
        'central_connection' => env('DB_CONNECTION', 'central'),

        /**
         * Connection used as a "template" for the dynamically created tenant database connection.
         * Note: don't name your template connection tenant. That name is reserved by package.
         */
        'template_tenant_connection' => null,

        /**
         * Tenant database names are created like this:
         * prefix + tenant_id + suffix.
         */
        'prefix' => 'tenant',
        'suffix' => '',

        /**
         * TenantDatabaseManagers are classes that handle the creation & deletion of tenant databases.
         */
        'managers' => [
            'sqlite' => SQLiteDatabaseManager::class,
            'mysql' => MySQLDatabaseManager::class,
            'mariadb' => MySQLDatabaseManager::class,
            'pgsql' => PostgreSQLDatabaseManager::class,

        /**
         * Use this database manager for MySQL to have a DB user created for each tenant database.
         * You can customize the grants given to these users by changing the $grants property.
         */
            // 'mysql' => Stancl\Tenancy\TenantDatabaseManagers\PermissionControlledMySQLDatabaseManager::class,

        /**
         * Disable the pgsql manager above, and enable the one below if you
         * want to separate tenant DBs by schemas rather than databases.
         */
            // 'pgsql' => Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLSchemaManager::class, // Separate by schema instead of database
        ],
    ],

    /**
     * Cache tenancy config. Used by CacheTenancyBootstrapper.
     *
     * This works for all Cache facade calls, cache() helper
     * calls and direct calls to injected cache stores.
     *
     * Each key in cache will have a tag applied on it. This tag is used to
     * scope the cache both when writing to it and when reading from it.
     *
     * You can clear cache selectively by specifying the tag.
     */
    'cache' => [
        'tag_base' => 'tenant', // This tag_base, followed by the tenant_id, will form a tag that will be applied on each cache call.
    ],

    /**
     * Filesystem tenancy config. Used by FilesystemTenancyBootstrapper.
     * https://tenancyforlaravel.com/docs/v3/tenancy-bootstrappers/#filesystem-tenancy-boostrapper.
     */
    'filesystem' => [
        /**
         * Each disk listed in the 'disks' array will be suffixed by the suffix_base, followed by the tenant_id.
         */
        'suffix_base' => 'tenant',
        'disks' => [
            'local',
            // 'public' intentionally excluded: media library uses the central public disk
            // so that storage:link + standard /storage URLs work across all tenant subdomains.
            // Tenant isolation for media is enforced by tenant_id on the media table.
            // 's3',
        ],

        /**
         * Use this for local disks.
         *
         * See https://tenancyforlaravel.com/docs/v3/tenancy-bootstrappers/#filesystem-tenancy-boostrapper
         */
        'root_override' => [
            // Disks whose roots should be overridden after storage_path() is suffixed.
            'local' => '%storage_path%/app/',
        ],

        /**
         * Should storage_path() be suffixed.
         *
         * Note: Disabling this will likely break local disk tenancy. Only disable this if you're using an external file storage service like S3.
         *
         * For the vast majority of applications, this feature should be enabled. But in some
         * edge cases, it can cause issues (like using Passport with Vapor - see #196), so
         * you may want to disable this if you are experiencing these edge case issues.
         */
        'suffix_storage_path' => true,

        /**
         * By default, asset() calls are made multi-tenant too. You can use global_asset() and mix()
         * for global, non-tenant-specific assets. However, you might have some issues when using
         * packages that use asset() calls inside the tenant app. To avoid such issues, you can
         * disable asset() helper tenancy and explicitly use tenant_asset() calls in places
         * where you want to use tenant-specific assets (product images, avatars, etc).
         */
        'asset_helper_tenancy' => false,
    ],

    /**
     * Redis tenancy config. Used by RedisTenancyBootstrapper.
     *
     * Note: You need phpredis to use Redis tenancy.
     *
     * Note: You don't need to use this if you're using Redis only for cache.
     * Redis tenancy is only relevant if you're making direct Redis calls,
     * either using the Redis facade or by injecting it as a dependency.
     */
    'redis' => [
        'prefix_base' => 'tenant', // Each key in Redis will be prepended by this prefix_base, followed by the tenant id.
        'prefixed_connections' => [ // Redis connections whose keys are prefixed, to separate one tenant's keys from another.
            // 'default',
        ],
    ],

    /**
     * Features are classes that provide additional functionality
     * not needed for tenancy to be bootstrapped. They are run
     * regardless of whether tenancy has been initialized.
     *
     * See the documentation page for each class to
     * understand which ones you want to enable.
     */
    'features' => [
        // Stancl\Tenancy\Features\UserImpersonation::class,
        // Stancl\Tenancy\Features\TelescopeTags::class,
        // Routes tagged with the "universal" middleware group initialize tenancy on
        // tenant domains and silently skip it on central domains (instead of 404ing).
        // Required for the shared Livewire update endpoint to work on operator subdomains.
        UniversalRoutes::class,
        // Stancl\Tenancy\Features\TenantConfig::class, // https://tenancyforlaravel.com/docs/v3/features/tenant-config
        // Stancl\Tenancy\Features\CrossDomainRedirect::class, // https://tenancyforlaravel.com/docs/v3/features/cross-domain-redirect
        // Stancl\Tenancy\Features\ViteBundler::class,
    ],

    /**
     * Should tenancy routes be registered.
     *
     * Tenancy routes include tenant asset routes. By default, this route is
     * enabled. But it may be useful to disable them if you use external
     * storage (e.g. S3 / Dropbox) or have a custom asset controller.
     */
    'routes' => true,

    /**
     * Parameters used by the tenants:migrate command.
     */
    'migration_parameters' => [
        '--force' => true, // This needs to be true to run migrations in production.
        '--path' => [database_path('migrations/tenant')],
        '--realpath' => true,
    ],

    /**
     * Parameters used by the tenants:seed command.
     */
    'seeder_parameters' => [
        '--class' => 'DatabaseSeeder', // root seeder class
        // '--force' => true, // This needs to be true to seed tenant databases in production
    ],
];
