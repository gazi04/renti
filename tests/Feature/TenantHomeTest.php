<?php

use App\Enums\VehicleStatus;
use App\Models\Booking;
use App\Models\Review;
use App\Models\Tenant;
use App\Models\Vehicle;

afterEach(fn () => tenancy()->end());

// ── Test helpers ────────────────────────────────────────────────────────────

function homeTenant(string $subdomain): Tenant
{
    return Tenant::factory()->withDomain($subdomain)->create();
}

function homeVehicle(array $attrs = []): Vehicle
{
    return Vehicle::factory()->create(array_merge([
        'is_public' => true,
        'status' => VehicleStatus::Available,
        'daily_rate' => 50,
    ], $attrs));
}

// ── Default content ──────────────────────────────────────────────────────────

it('renders the home page with default content when nothing is configured', function () {
    homeTenant('homedef');

    $this->get(tenant_url('homedef', '/'))
        ->assertOk()
        ->assertSee(__('booking.home_hero_heading'))
        ->assertSee(__('booking.home_about_title'))
        ->assertSee(__('booking.home_service_1_title'));
});

// ── Custom content ────────────────────────────────────────────────────────────

it('renders operator-customized hero, services and about content', function () {
    $tenant = homeTenant('homecustom');

    tenancy()->initialize($tenant);
    $tenant->setSetting('home_hero_heading', 'Drive Prishtina in style');
    $tenant->setSetting('home_hero_subheading', 'Custom subheading here');
    $tenant->setSetting('home_about_title', 'Our family business');
    $tenant->setSetting('home_about_text', 'Since 1999 we rent cars.');
    $tenant->setSetting('home_service_1_title', 'Airport pickup');
    tenancy()->end();

    $this->get(tenant_url('homecustom', '/'))
        ->assertOk()
        ->assertSee('Drive Prishtina in style')
        ->assertSee('Custom subheading here')
        ->assertSee('Our family business')
        ->assertSee('Since 1999 we rent cars.')
        ->assertSee('Airport pickup')
        ->assertDontSee(__('booking.home_hero_heading'));
});

// ── Bilingual content ─────────────────────────────────────────────────────────

it('shows the content for the visitor\'s chosen language', function () {
    $tenant = homeTenant('homelang');

    tenancy()->initialize($tenant);
    $tenant->setSetting('home_hero_heading_sq', 'Vozit me stil');
    $tenant->setSetting('home_hero_heading_en', 'Drive in style');
    tenancy()->end();

    // No session locale and no tenant default_locale set — falls through to sq.
    $this->get(tenant_url('homelang', '/'))
        ->assertOk()
        ->assertSee('Vozit me stil')
        ->assertDontSee('Drive in style');

    $this->withSession(['locale' => 'en'])
        ->get(tenant_url('homelang', '/'))
        ->assertOk()
        ->assertSee('Drive in style')
        ->assertDontSee('Vozit me stil');
});

it('uses the tenant\'s configured default locale when the visitor has no session override', function () {
    $tenant = homeTenant('homelocaledefault');

    tenancy()->initialize($tenant);
    $tenant->setSetting('default_locale', 'en');
    tenancy()->end();

    // No session locale set — falls through to the tenant's configured default.
    $this->get(tenant_url('homelocaledefault', '/'))
        ->assertOk()
        ->assertSee(trans('booking.home_hero_heading', [], 'en'))
        ->assertDontSee(trans('booking.home_hero_heading', [], 'sq'));
});

it('lets an explicit session locale win over the tenant default', function () {
    $tenant = homeTenant('homelocalesession');

    tenancy()->initialize($tenant);
    $tenant->setSetting('default_locale', 'en');
    tenancy()->end();

    $this->withSession(['locale' => 'sq'])
        ->get(tenant_url('homelocalesession', '/'))
        ->assertOk()
        ->assertSee(trans('booking.home_hero_heading', [], 'sq'))
        ->assertDontSee(trans('booking.home_hero_heading', [], 'en'));
});

it('falls back to the other language when only one is filled', function () {
    $tenant = homeTenant('homelangfb');

    tenancy()->initialize($tenant);
    $tenant->setSetting('home_hero_heading_sq', 'Vetëm shqip');
    tenancy()->end();

    // English visitor still sees the Albanian value rather than nothing.
    $this->withSession(['locale' => 'en'])
        ->get(tenant_url('homelangfb', '/'))
        ->assertOk()
        ->assertSee('Vetëm shqip');
});

it('escapes HTML in operator-provided home content', function () {
    $tenant = homeTenant('homexss');

    tenancy()->initialize($tenant);
    $tenant->setSetting('home_hero_heading', '<script>alert("xss")</script>');
    tenancy()->end();

    $this->get(tenant_url('homexss', '/'))
        ->assertOk()
        ->assertDontSee('<script>alert("xss")</script>', false)
        ->assertSee('&lt;script&gt;', false);
});

// ── Featured vehicles ─────────────────────────────────────────────────────────

it('shows only public + available vehicles in the featured section', function () {
    $tenant = homeTenant('homefeat');

    tenancy()->initialize($tenant);
    homeVehicle(['name' => 'Featured Corolla']);
    Vehicle::factory()->private()->create(['name' => 'Hidden Golf']);
    Vehicle::factory()->underMaintenance()->create(['name' => 'Broken Passat']);
    tenancy()->end();

    $this->get(tenant_url('homefeat', '/'))
        ->assertOk()
        ->assertSee('Featured Corolla')
        ->assertDontSee('Hidden Golf')
        ->assertDontSee('Broken Passat');
});

it('does not leak another tenant vehicles into the featured section', function () {
    $tenantA = homeTenant('homea');
    $tenantB = homeTenant('homeb');

    tenancy()->initialize($tenantA);
    homeVehicle(['name' => 'Alpha Featured']);
    tenancy()->end();

    tenancy()->initialize($tenantB);
    homeVehicle(['name' => 'Beta Featured']);
    tenancy()->end();

    $this->get(tenant_url('homea', '/'))
        ->assertOk()
        ->assertSee('Alpha Featured')
        ->assertDontSee('Beta Featured');
});

// ── Layout rendering ──────────────────────────────────────────────────────────

/*
 * The seven selectable home layouts were consolidated into one shell, so the
 * per-variant dataset and the invalid-slug fallback test both described a
 * concept that no longer exists. What still needs asserting is simply that the
 * one shell renders its sections.
 */
it('renders the home page shell with its hero, services and fleet', function () {
    $tenant = homeTenant('homeshell');

    tenancy()->initialize($tenant);
    homeVehicle(['name' => 'Layout Car']);
    tenancy()->end();

    $this->get(tenant_url('homeshell', '/'))
        ->assertOk()
        ->assertSee(__('booking.home_hero_heading'))
        ->assertSee(__('booking.home_services_heading'))
        ->assertSee(__('booking.featured_vehicles'))
        ->assertSee('Layout Car');
});

it('renders the trust strip and closing CTA banner', function () {
    homeTenant('hometrust');

    $this->get(tenant_url('hometrust', '/'))
        ->assertOk()
        ->assertSee(__('booking.home_trust_free_cancellation'))
        ->assertSee(__('booking.home_trust_instant_confirmation'))
        ->assertSee(__('booking.home_trust_comprehensive_insurance'))
        ->assertSee(__('booking.home_trust_24_7_support'))
        ->assertSee(__('booking.home_cta_heading'));
});

it('ignores a stale layout setting left over from the old variant system', function () {
    $tenant = homeTenant('homestalelay');

    tenancy()->initialize($tenant);
    // Written straight to the table: the key is no longer on the allow-list.
    $tenant->tenantSettings()->create(['key' => 'layout_home', 'value' => '../../etc/passwd']);
    tenancy()->end();

    $this->get(tenant_url('homestalelay', '/'))
        ->assertOk()
        ->assertSee(__('booking.home_hero_heading'));
});

it('renders featured vehicles with their cover photos on the home page', function () {
    $tenant = homeTenant('homephotos');

    tenancy()->initialize($tenant);
    fakeTenantDisks();

    // Two vehicles, one photo each: the featured query eager-loads media for the
    // whole page, so the media hydration is multi-row — the only shape that arms
    // preventLazyLoading() and therefore the only shape that actually exercises
    // the tenant-aware path generator on this page.
    $first = homeVehicle(['name' => 'Featured Golf']);
    $second = homeVehicle(['name' => 'Featured Passat']);
    attachVehiclePhotos($first, 1);
    attachVehiclePhotos($second, 1);

    tenancy()->end();

    $this->get(tenant_url('homephotos', '/'))
        ->assertOk()
        ->assertSee('Featured Golf')
        ->assertSee('Featured Passat')
        ->assertSee("/storage/tenants/{$tenant->id}/vehicle_photos/", escape: false);
});

// ── About stat grid ───────────────────────────────────────────────────────────

it('shows the real fleet size on the about section', function () {
    $tenant = homeTenant('homestats');

    tenancy()->initialize($tenant);
    homeVehicle();
    homeVehicle();
    homeVehicle();
    // Private/maintenance vehicles must not inflate the public count.
    Vehicle::factory()->private()->create();
    Vehicle::factory()->underMaintenance()->create();
    tenancy()->end();

    $this->get(tenant_url('homestats', '/'))
        ->assertOk()
        ->assertSeeInOrder(['3', __('booking.home_stat_fleet_size')]);
});

it('hides the average-rating stat when there are no approved reviews yet', function () {
    $tenant = homeTenant('homestatsnorev');

    tenancy()->initialize($tenant);
    homeVehicle();
    tenancy()->end();

    $this->get(tenant_url('homestatsnorev', '/'))
        ->assertOk()
        ->assertSee(__('booking.home_stat_fleet_size'))
        ->assertDontSee(__('booking.home_stat_average_rating'));
});

it('shows the average-rating stat once an approved review exists', function () {
    $tenant = homeTenant('homestatsrev');

    tenancy()->initialize($tenant);
    $vehicle = homeVehicle();
    $booking = Booking::factory()->forVehicle($vehicle)->completed()->create();
    Review::factory()->approved()->create(['booking_id' => $booking->id, 'vehicle_id' => $vehicle->id, 'rating' => 5]);
    tenancy()->end();

    $this->get(tenant_url('homestatsrev', '/'))
        ->assertOk()
        ->assertSee(__('booking.home_stat_average_rating'));
});
