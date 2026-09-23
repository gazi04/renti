<?php

use App\Models\Tenant;

afterEach(fn () => tenancy()->end());

it('renders the branded 404 page for an unknown subdomain, with no tenant resolved', function () {
    $response = $this->get(tenant_url('does-not-exist-404-test'));

    $response->assertNotFound()
        ->assertSee(__('booking.not_found_heading'))
        ->assertSee(__('booking.not_found_back_home'))
        ->assertSee(__('booking.not_found_browse_vehicles'))
        ->assertSee(config('app.name'));
});

it('renders the branded 404 page for a nonexistent vehicle on a real tenant, with that tenant\'s own branding', function () {
    $tenant = Tenant::factory()->withDomain('notfound404')->create(['name' => 'Ardi Rent A Car']);

    $response = $this->get(tenant_url('notfound404', '/vehicles/999999'));

    $response->assertNotFound()
        ->assertSee(__('booking.not_found_heading'))
        ->assertSee('Ardi Rent A Car');
});
