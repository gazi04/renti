<?php

use App\Enums\PlanFeature;
use App\Exceptions\PromoCodeInvalidException;
use App\Filament\Operator\Resources\Bookings\Pages\CreateBooking;
use App\Filament\Operator\Resources\PromoCodes\Pages\CreatePromoCode;
use App\Filament\Operator\Resources\PromoCodes\Pages\ListPromoCodes;
use App\Filament\Operator\Resources\PromoCodes\PromoCodeResource;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\PromoCode;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\BookingService;
use App\Services\PricingService;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

covers(PromoCode::class, BookingService::class);

afterEach(fn () => tenancy()->end());

/**
 * @param  array<string, mixed>  $planFeatures
 * @return array{0: Tenant, 1: User}
 */
function promoTenant(string $domain, array $planFeatures = [], ?string $planSlug = null): array
{
    if ($planSlug !== null) {
        Plan::factory()->create(['slug' => $planSlug, 'features' => $planFeatures]);
    }

    $tenant = Tenant::factory()->withDomain($domain)->create(['plan' => $planSlug ?? 'ghost-plan']);

    $owner = new User;
    $owner->forceFill([
        'tenant_id' => $tenant->id,
        'role' => 'operator',
        'name' => 'Owner',
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
    ])->save();

    tenancy()->initialize($tenant);
    Filament::setCurrentPanel(Filament::getPanel('operator'));
    actingAs($owner);

    return [$tenant, $owner];
}

/** @return array<string, mixed> */
function promoBookingData(Vehicle $vehicle, array $overrides = []): array
{
    return array_merge([
        'vehicle_id' => $vehicle->id,
        'customer_name' => 'Arben',
        'customer_phone' => '+38344111222',
        'start_date' => '2030-06-01',
        'end_date' => '2030-06-04',
    ], $overrides);
}

it('computes discountFor and validity', function () {
    promoTenant('promounit');

    $pct = PromoCode::factory()->make(['type' => 'percentage', 'value' => 10]);
    $fixed = PromoCode::factory()->make(['type' => 'fixed', 'value' => 15]);

    expect($pct->discountFor(100))->toBe(10.0)
        ->and($fixed->discountFor(8))->toBe(8.0) // capped at amount
        ->and(PromoCode::factory()->inactive()->make()->isCurrentlyValid())->toBeFalse()
        ->and(PromoCode::factory()->expired()->make()->isCurrentlyValid())->toBeFalse()
        ->and(PromoCode::factory()->make(['max_uses' => 0])->isCurrentlyValid())->toBeFalse();
});

// ─── isValidForCustomer (deep-audit finding 06) ───────────────────────────────
// The single source of truth both previewPromo() and resolvePromo() now share.

it('skips the per-customer check when no customer is known yet', function () {
    promoTenant('promonocust');
    $promo = PromoCode::factory()->create(['per_customer_limit' => 1]);

    // Mirrors step 1 of the wizard: no phone typed yet, so nothing to check —
    // this is exactly what let today's step-1 preview stay permissive.
    expect($promo->isValidForCustomer(null))->toBeTrue();
});

it('is valid for a customer under the per-customer limit', function () {
    promoTenant('promounderlimit');
    $vehicle = Vehicle::factory()->create();
    $promo = PromoCode::factory()->create(['per_customer_limit' => 2]);
    $customer = Customer::factory()->create();

    Booking::factory()->forVehicle($vehicle)->confirmed()->create([
        'customer_id' => $customer->id,
        'promo_code_id' => $promo->id,
    ]);

    expect($promo->isValidForCustomer($customer))->toBeTrue();
});

it('is invalid for a customer at the per-customer limit', function () {
    promoTenant('promoatlimit');
    $vehicle = Vehicle::factory()->create();
    $promo = PromoCode::factory()->create(['per_customer_limit' => 1]);
    $customer = Customer::factory()->create();

    Booking::factory()->forVehicle($vehicle)->confirmed()->create([
        'customer_id' => $customer->id,
        'promo_code_id' => $promo->id,
    ]);

    expect($promo->isValidForCustomer($customer))->toBeFalse();
});

it('ignores a cancelled redemption when counting the per-customer limit', function () {
    promoTenant('promocancelledlimit');
    $vehicle = Vehicle::factory()->create();
    $promo = PromoCode::factory()->create(['per_customer_limit' => 1]);
    $customer = Customer::factory()->create();

    Booking::factory()->forVehicle($vehicle)->cancelled()->create([
        'customer_id' => $customer->id,
        'promo_code_id' => $promo->id,
    ]);

    expect($promo->isValidForCustomer($customer))->toBeTrue();
});

it('is invalid for any customer when the code itself is not currently valid', function () {
    promoTenant('promoinactivelimit');
    $promo = PromoCode::factory()->inactive()->create(['per_customer_limit' => 1]);
    $customer = Customer::factory()->create();

    expect($promo->isValidForCustomer($customer))->toBeFalse()
        ->and($promo->isValidForCustomer(null))->toBeFalse();
});

it('lowers the total in PricingService', function () {
    promoTenant('promoprice');
    $vehicle = Vehicle::factory()->create(['daily_rate' => 50]);
    $promo = PromoCode::factory()->create(['type' => 'percentage', 'value' => 10]);

    $start = Carbon::parse('2030-06-01');
    $end = Carbon::parse('2030-06-04'); // 3 days * 50 = 150

    $plain = app(PricingService::class)->calculate($vehicle, $start, $end);
    $discounted = app(PricingService::class)->calculate($vehicle, $start, $end, $promo);

    expect($plain['total'])->toBe(150.0)
        ->and($discounted['discount'])->toBe(15.0)
        ->and($discounted['total'])->toBe(135.0);
});

it('applies and records a valid promo on a booking', function () {
    promoTenant('promobook');
    $vehicle = Vehicle::factory()->create(['daily_rate' => 50]);
    $promo = PromoCode::factory()->create(['code' => 'SAVE10', 'type' => 'percentage', 'value' => 10]);

    $booking = app(BookingService::class)->create(promoBookingData($vehicle, ['promo_code' => 'save10']));

    expect((float) $booking->discount_amount)->toBe(15.0)
        ->and($booking->promo_code_id)->toBe($promo->id)
        ->and($promo->fresh()->redeemedUsesCount())->toBe(0); // still Pending — not yet redeemed

    app(BookingService::class)->confirm($booking);

    expect($promo->fresh()->redeemedUsesCount())->toBe(1);
});

it('does not burn the max_uses cap on a rejected booking', function () {
    promoTenant('promoreject');
    $vehicle = Vehicle::factory()->create(['daily_rate' => 50]);
    $promo = PromoCode::factory()->create(['code' => 'CAPPED', 'max_uses' => 1]);

    $first = app(BookingService::class)->create(promoBookingData($vehicle, [
        'promo_code' => 'CAPPED',
        'customer_phone' => '+38344111222',
        'start_date' => '2030-06-01', 'end_date' => '2030-06-03',
    ]));
    app(BookingService::class)->reject($first);

    $second = app(BookingService::class)->create(promoBookingData($vehicle, [
        'promo_code' => 'CAPPED',
        'customer_phone' => '+38344999888',
        'start_date' => '2030-07-01', 'end_date' => '2030-07-03',
    ]));

    expect($second->promo_code_id)->toBe($promo->id);
});

it('does not burn the max_uses cap on a cancelled booking', function () {
    promoTenant('promocancel');
    $vehicle = Vehicle::factory()->create(['daily_rate' => 50]);
    $promo = PromoCode::factory()->create(['code' => 'CAPPED2', 'max_uses' => 1]);

    $first = app(BookingService::class)->create(promoBookingData($vehicle, [
        'promo_code' => 'CAPPED2',
        'customer_phone' => '+38344111222',
        'start_date' => '2030-06-01', 'end_date' => '2030-06-03',
    ]));
    app(BookingService::class)->cancel($first);

    $second = app(BookingService::class)->create(promoBookingData($vehicle, [
        'promo_code' => 'CAPPED2',
        'customer_phone' => '+38344999888',
        'start_date' => '2030-07-01', 'end_date' => '2030-07-03',
    ]));

    expect($second->promo_code_id)->toBe($promo->id);
});

it('rejects an invalid or expired code and creates no booking', function () {
    promoTenant('promobad');
    $vehicle = Vehicle::factory()->create(['daily_rate' => 50]);
    PromoCode::factory()->expired()->create(['code' => 'OLD']);

    expect(fn () => app(BookingService::class)->create(promoBookingData($vehicle, ['promo_code' => 'OLD'])))
        ->toThrow(PromoCodeInvalidException::class);

    expect(Booking::query()->count())->toBe(0);
});

it('ignores the code when the plan disables promo codes', function () {
    promoTenant('promooff', [PlanFeature::PromoCodes->value => false], 'nopromo');
    $vehicle = Vehicle::factory()->create(['daily_rate' => 50]);
    PromoCode::factory()->create(['code' => 'SAVE10', 'type' => 'percentage', 'value' => 10]);

    $booking = app(BookingService::class)->create(promoBookingData($vehicle, ['promo_code' => 'SAVE10']));

    expect((float) $booking->discount_amount)->toBe(0.0)
        ->and($booking->promo_code_id)->toBeNull();
});

it('enforces the per-customer limit', function () {
    promoTenant('promopercust');
    $vehicle = Vehicle::factory()->create(['daily_rate' => 50]);
    PromoCode::factory()->create(['code' => 'ONCE', 'per_customer_limit' => 1]);

    $first = app(BookingService::class)->create(promoBookingData($vehicle, [
        'promo_code' => 'ONCE',
        'start_date' => '2030-06-01', 'end_date' => '2030-06-03',
    ]));

    expect(fn () => app(BookingService::class)->create(promoBookingData($vehicle, [
        'promo_code' => 'ONCE',
        'start_date' => '2030-07-01', 'end_date' => '2030-07-03', // same phone, different dates
    ])))->toThrow(PromoCodeInvalidException::class);

    // Once the first attempt is rejected, the same customer can reuse the code (M2).
    app(BookingService::class)->reject($first);

    $second = app(BookingService::class)->create(promoBookingData($vehicle, [
        'promo_code' => 'ONCE',
        'start_date' => '2030-07-01', 'end_date' => '2030-07-03',
    ]));

    expect($second->promo_code_id)->not->toBeNull();
});

it('previews the promo discount on the public booking component', function () {
    [$tenant] = promoTenant('promopublic');
    tenancy()->end();

    tenancy()->initialize($tenant);
    $vehicle = Vehicle::factory()->create(['daily_rate' => 50, 'is_public' => true]);
    PromoCode::factory()->create(['code' => 'SAVE10', 'type' => 'percentage', 'value' => 10]);

    Livewire::test('pages::public.vehicle-booking', ['vehicle' => $vehicle])
        ->dispatch('dates-selected', start: '2030-06-01 10:00', end: '2030-06-04 10:00')
        ->set('promoCode', 'SAVE10')
        ->call('applyPromo')
        ->assertSet('priceBreakdown.total', 135.0)
        ->assertSet('promoNotice', __('booking.promo_applied'));
});

it('drops a promo that no longer qualifies once the phone number is known, before reaching the review screen', function () {
    // Reproduces the audit's exact scenario: step 1 says "applied" for a
    // repeat customer, because no phone is known yet to check per_customer_limit.
    [$tenant] = promoTenant('promostep2drop');
    $vehicle = Vehicle::factory()->create(['daily_rate' => 50]);
    $promo = PromoCode::factory()->create(['code' => 'ONCE', 'type' => 'percentage', 'value' => 10, 'per_customer_limit' => 1]);
    $customer = Customer::factory()->create(['phone' => '+38344999888']);

    Booking::factory()->forVehicle($vehicle)->confirmed()->create([
        'customer_id' => $customer->id,
        'promo_code_id' => $promo->id,
    ]);

    tenancy()->end();
    tenancy()->initialize($tenant);

    Livewire::test('pages::public.vehicle-booking', ['vehicle' => $vehicle])
        ->dispatch('dates-selected', start: '2030-06-01 10:00', end: '2030-06-04 10:00')
        ->set('promoCode', 'ONCE')
        ->call('applyPromo')
        ->assertSet('priceBreakdown.total', 135.0) // step 1: no phone known yet, still "applied"
        ->call('nextStep') // step 1 -> 2
        ->set('customerName', 'Arben')
        ->set('customerPhone', '+38344999888')
        ->set('customerEmail', 'arben@example.com')
        ->call('nextStep') // step 2 -> 3: phone now known, re-checked
        ->assertSet('step', 3)
        ->assertSet('promoCode', '')
        ->assertSet('priceBreakdown.total', 150.0) // discount dropped
        ->assertSet('promoError', __('booking.promo_removed_recalculated'));
});

it('recovers gracefully when a promo fails at the final submit despite passing the step-2 re-check', function () {
    [$tenant] = promoTenant('promosubmitrace');
    $vehicle = Vehicle::factory()->create(['daily_rate' => 50]);
    $promo = PromoCode::factory()->create(['code' => 'RACE', 'type' => 'percentage', 'value' => 10, 'per_customer_limit' => 1]);

    tenancy()->end();
    tenancy()->initialize($tenant);

    $component = Livewire::test('pages::public.vehicle-booking', ['vehicle' => $vehicle])
        ->dispatch('dates-selected', start: '2030-06-01 10:00', end: '2030-06-04 10:00')
        ->set('promoCode', 'RACE')
        ->call('applyPromo')
        ->call('nextStep') // step 1 -> 2, no phone yet at re-check time
        ->set('customerName', 'Arben')
        ->set('customerPhone', '+38344777666')
        ->set('customerEmail', 'arben@example.com')
        ->call('nextStep') // step 2 -> 3: first use of this phone, re-check still passes
        ->assertSet('step', 3)
        ->assertSet('priceBreakdown.total', 135.0);

    // The race: another booking consumes the same customer's single use between
    // the step-2 re-check and the submit click.
    $customer = Customer::firstOrCreate(['phone' => '+38344777666'], ['name' => 'Arben']);
    Booking::factory()->forVehicle($vehicle)->confirmed()->create([
        'customer_id' => $customer->id,
        'customer_phone' => '+38344777666',
        'promo_code_id' => $promo->id,
        'start_date' => '2030-07-01',
        'end_date' => '2030-07-04',
    ]);

    $component->set('termsAccepted', true)
        ->call('submit')
        ->assertSet('promoCode', '')
        ->assertSet('priceBreakdown.total', 150.0)
        ->assertSet('promoError', __('booking.promo_removed_recalculated'));

    expect(Booking::query()->where('customer_phone', '+38344777666')->count())->toBe(1); // only the race one, not a second
});

it('flags an invalid code on the public component', function () {
    [$tenant] = promoTenant('promopublicbad');
    tenancy()->end();

    tenancy()->initialize($tenant);
    $vehicle = Vehicle::factory()->create(['daily_rate' => 50, 'is_public' => true]);

    Livewire::test('pages::public.vehicle-booking', ['vehicle' => $vehicle])
        ->dispatch('dates-selected', start: '2030-06-01 10:00', end: '2030-06-04 10:00')
        ->set('promoCode', 'NOPE')
        ->call('applyPromo')
        ->assertSet('priceBreakdown.total', 150.0)
        ->assertSet('promoError', __('booking.promo_invalid'));
});

it('lets the owner create and list promo codes, tenant-scoped', function () {
    [$tenant] = promoTenant('promores', [PlanFeature::PromoCodes->value => true], 'withpromo');

    Livewire::test(CreatePromoCode::class)
        ->fillForm(['code' => 'summer', 'type' => 'percentage', 'value' => 20, 'is_active' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    $promo = PromoCode::query()->first();
    expect($promo->code)->toBe('SUMMER') // uppercased
        ->and($promo->tenant_id)->toBe($tenant->id);

    Livewire::test(ListPromoCodes::class)->assertSee('SUMMER');
});

it('gates the promo resource by owner and plan', function () {
    [$tenant] = promoTenant('promogate', [PlanFeature::PromoCodes->value => true], 'gatepromo');
    expect(PromoCodeResource::canAccess())->toBeTrue();

    $staff = User::factory()->staff()->create(['tenant_id' => $tenant->id]);
    actingAs($staff);
    expect(PromoCodeResource::canAccess())->toBeFalse();
});

it('hides the promo resource from an owner whose plan disables it', function () {
    // The test above only ever passes PromoCodes => true and then swaps role, so
    // it proves the owner half and nothing about the plan half. Stay the owner
    // here so the role check can't be what fails it.
    promoTenant('promogateoff', [PlanFeature::PromoCodes->value => false], 'offpromo');

    expect(PromoCodeResource::canAccess())->toBeFalse();
});

it('hides the promo field on the manual booking form when the plan disables it', function () {
    promoTenant('promoform', [PlanFeature::PromoCodes->value => false], 'formpromo');
    Vehicle::factory()->create(['daily_rate' => 50]);

    Livewire::test(CreateBooking::class)
        ->assertFormFieldHidden('promo_code');
});

it('shows the promo field on the manual booking form when the plan enables it', function () {
    // Pairs with the test above: without an ON case, that one would still pass
    // if the field were deleted outright.
    promoTenant('promoformon', [PlanFeature::PromoCodes->value => true], 'formpromoon');
    Vehicle::factory()->create(['daily_rate' => 50]);

    Livewire::test(CreateBooking::class)
        ->assertFormFieldVisible('promo_code');
});

it('hides the promo input on the public booking page when the plan disables it', function () {
    [$tenant] = promoTenant('promopublicoff', [PlanFeature::PromoCodes->value => false], 'offpublicpromo');
    tenancy()->end();

    tenancy()->initialize($tenant);
    $vehicle = Vehicle::factory()->create(['daily_rate' => 50, 'is_public' => true]);
    PromoCode::factory()->create(['code' => 'SAVE10', 'type' => 'percentage', 'value' => 10]);

    // The public promo tests above all use a ghost plan, which defaults the
    // feature ON — so the blade's gate-off branch was never exercised.
    Livewire::test('pages::public.vehicle-booking', ['vehicle' => $vehicle])
        ->dispatch('dates-selected', start: '2030-06-01 10:00', end: '2030-06-04 10:00')
        ->assertDontSee(__('booking.promo_label'))
        ->set('promoCode', 'SAVE10')
        ->call('applyPromo')
        // A real, valid code — silently ignored, because the component's own
        // gate bails before the code is ever looked up.
        ->assertSet('priceBreakdown.total', 150.0);
});

it('never returns a negative or oversized discount', function () {
    promoTenant('promofloor', [PlanFeature::PromoCodes->value => true], 'promofloorplan');

    $percentage = PromoCode::factory()->create(['type' => 'percentage', 'value' => 10]);
    $fixed = PromoCode::factory()->fixed(500)->create();
    $unknown = PromoCode::factory()->create(['type' => 'mystery', 'value' => 25]);

    expect($percentage->discountFor(200.0))->toBe(20.0)
        // A zero-value order can't yield a discount, whatever the code says.
        ->and($percentage->discountFor(0.0))->toBe(0.0)
        ->and($fixed->discountFor(0.0))->toBe(0.0)
        // A fixed discount never exceeds what is being discounted.
        ->and($fixed->discountFor(100.0))->toBe(100.0)
        // An unrecognised type is worth nothing, not a negative.
        ->and($unknown->discountFor(200.0))->toBe(0.0);
});
