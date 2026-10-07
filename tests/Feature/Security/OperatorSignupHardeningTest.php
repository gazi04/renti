<?php

declare(strict_types=1);

use App\Filament\Resources\Tenants\Pages\CreateTenant;
use App\Models\Booking;
use App\Models\Tenant;
use App\Models\User;
use App\Rules\AvailableSubdomain;
use App\Services\RentalAgreementService;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Stancl\Tenancy\Database\Models\Domain;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseMissing;

afterEach(fn () => tenancy()->end());

/*
|--------------------------------------------------------------------------
| Operator signup hardening
|--------------------------------------------------------------------------
|
| The deferred items from the multi-tenancy red team. None of these
| are cross-tenant reads — the BelongsToTenant scope holds. They are the soft
| edges around it: unlimited tenant creation, a subdomain claim two requests can
| both win, and a reserved-name list thin enough to let an operator squat a
| platform hostname.
|
*/

/**
 * Fill the signup form with a valid, unique set of details.
 *
 * @param  array<string, string>  $overrides
 */
function signupAttempt(array $overrides = []): Testable
{
    $component = Livewire::test('pages::auth.operator-register');

    $defaults = [
        'name' => 'Ardi Rent A Car',
        'email' => 'ardi@example.com',
        'subdomain' => 'ardi',
        'password' => 'password',
        'password_confirmation' => 'password',
    ];

    foreach ([...$defaults, ...$overrides] as $property => $value) {
        $component->set($property, $value);
    }

    return $component;
}

/** The limiter key the page derives from the visitor's IP. */
function signupIpKey(): string
{
    return 'operator-register:127.0.0.1';
}

it('counts a failed signup attempt against the per-IP limiter', function () {
    RateLimiter::clear(signupIpKey());

    // Three attempts that all fail validation. The limiter used to be hit only
    // *after* validation passed, so these were free and only successful signups
    // were ever capped.
    foreach (range(1, 3) as $i) {
        signupAttempt(['email' => 'not-an-email', 'subdomain' => "biz{$i}"])
            ->call('register')
            ->assertHasErrors(['email']);
    }

    // A fourth attempt is refused before validation even runs — so a perfectly
    // valid one is now blocked too.
    signupAttempt(['email' => 'fine@example.com', 'subdomain' => 'fine'])
        ->call('register')
        ->assertHasErrors(['email']);

    assertDatabaseCount('tenants', 0);
})->group('security');

it('stops signups at the platform-wide hourly cap even from fresh IPs', function () {
    $cap = config()->integer('tenancy.signup_hourly_cap');

    // Saturate the global limiter directly: the per-IP limiter is what an attacker
    // escapes by rotating proxies, so the cap has to bite independently of it.
    foreach (range(1, $cap) as $ignored) {
        RateLimiter::hit('operator-register:global', 3600);
    }

    RateLimiter::clear(signupIpKey());

    signupAttempt()
        ->call('register')
        ->assertHasErrors(['email'])
        ->assertSet('registered', false);

    assertDatabaseCount('tenants', 0);
})->group('security');

it('throttles one email address across rotating IPs', function () {
    foreach (range(1, 3) as $i) {
        // Clearing the IP key between attempts models the attacker moving to a new
        // proxy; only the email-keyed limiter can still see the pattern. Each of
        // these fails on the reserved subdomain, so nothing is created and the
        // per-IP limiter alone would never have caught on.
        RateLimiter::clear(signupIpKey());

        signupAttempt(['email' => 'spray@example.com', 'subdomain' => 'admin'])
            ->call('register')
            ->assertHasErrors(['subdomain']);
    }

    RateLimiter::clear(signupIpKey());

    // A fourth attempt from yet another IP, this time with a perfectly valid
    // subdomain, is refused on the email key alone.
    signupAttempt(['email' => 'spray@example.com', 'subdomain' => 'sprayco'])
        ->call('register')
        ->assertHasErrors(['email'])
        ->assertSet('registered', false);

    assertDatabaseCount('tenants', 0);
})->group('security');

it('turns a subdomain lost to a concurrent insert into a field error, not a 500', function () {
    $rival = Tenant::factory()->create();

    // The availability check in AvailableSubdomain is a read followed by an insert.
    // Claiming the domain on Tenant::created reproduces exactly that window: the
    // rule has already passed, and the page's own insert is next.
    Tenant::created(function (Tenant $tenant) use ($rival): void {
        if ($tenant->isNot($rival)) {
            Domain::query()->create([
                'domain' => AvailableSubdomain::fullDomain('ardi'),
                'tenant_id' => $rival->id,
            ]);
        }
    });

    signupAttempt()
        ->call('register')
        ->assertHasErrors(['subdomain'])
        ->assertSet('registered', false);
})->group('security');

it('leaves no orphan tenant when the domain insert loses the race', function () {
    $rival = Tenant::factory()->create();

    Tenant::created(function (Tenant $tenant) use ($rival): void {
        if ($tenant->isNot($rival)) {
            Domain::query()->create([
                'domain' => AvailableSubdomain::fullDomain('ardi'),
                'tenant_id' => $rival->id,
            ]);
        }
    });

    signupAttempt()->call('register')->assertHasErrors(['subdomain']);

    // The tenant row was inserted before the collision. Without the transaction it
    // would survive, holding a plan and a name with no domain and no owner.
    expect(Tenant::count())->toBe(1)
        ->and(Tenant::query()->sole()->is($rival))->toBeTrue();

    assertDatabaseMissing('users', ['email' => 'ardi@example.com']);
})->group('security');

it('rejects every reserved subdomain at operator signup', function () {
    $reserved = config()->array('tenancy.reserved_subdomains');

    expect($reserved)->not->toBeEmpty();

    foreach ($reserved as $subdomain) {
        // Every attempt is refused before the limiter can run out, because a
        // rejected name never reaches the tenant insert.
        RateLimiter::clear(signupIpKey());
        RateLimiter::clear('operator-register:global');

        // A distinct email per iteration so the email-keyed limiter (3/day)
        // doesn't fire and mask the subdomain rejection under test.
        signupAttempt([
            'email' => "reserved-{$subdomain}@example.com",
            'subdomain' => $subdomain,
        ])
            ->call('register')
            ->assertHasErrors(['subdomain']);
    }

    assertDatabaseCount('tenants', 0);
})->group('security');

it('rejects a reserved subdomain on the admin create-tenant form', function () {
    // This form carried no reserved-name check at all until AvailableSubdomain was
    // shared with it — an admin could hand an operator "admin.<domain>".
    $admin = User::factory()->create(['tenant_id' => null, 'role' => 'admin']);

    actingAs($admin);

    Livewire::test(CreateTenant::class)
        ->fillForm([
            'name' => 'Squatter Co',
            'subdomain' => 'admin',
            'status' => 'pending',
        ])
        ->call('create')
        ->assertHasFormErrors(['subdomain']);

    assertDatabaseMissing('domains', ['domain' => AvailableSubdomain::fullDomain('admin')]);
})->group('security');

it('refuses to generate a rental agreement outside the owning tenant context', function () {
    $tenant = Tenant::factory()->withDomain('agreementco')->create();

    tenancy()->initialize($tenant);
    $booking = Booking::factory()->create();
    tenancy()->end();

    // The contract path embeds tenants/{id}/ but the default disk is ALSO
    // tenant-suffixed, so writing this from central context silently targets a
    // different root — and the PDF carries customer PII.
    expect(fn () => resolve(RentalAgreementService::class)->generate($booking))
        ->toThrow(RuntimeException::class);
})->group('security');

it('refuses to generate a rental agreement while another tenant is current', function () {
    $owner = Tenant::factory()->withDomain('ownerco')->create();
    $other = Tenant::factory()->withDomain('otherco')->create();

    tenancy()->initialize($owner);
    $booking = Booking::factory()->create();
    tenancy()->end();

    tenancy()->initialize($other);

    expect(fn () => resolve(RentalAgreementService::class)->generate($booking))
        ->toThrow(RuntimeException::class);
})->group('security');
