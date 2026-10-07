<?php

use App\Enums\PlanFeature;
use App\Filament\Operator\Resources\Reviews\Pages\ListReviews;
use App\Filament\Operator\Resources\Reviews\ReviewResource;
use App\Jobs\RequestReviewsJob;
use App\Mail\BookingReviewRequestMail;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Review;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

afterEach(fn () => tenancy()->end());

/**
 * @param  array<string, mixed>  $planFeatures
 * @return array{0: Tenant, 1: User}
 */
function reviewTenant(string $domain, array $planFeatures = [], ?string $planSlug = null): array
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

// ── Public submission page ───────────────────────────────────────────────────

it('renders the review form on a valid signed link and creates an unapproved review on submit', function () {
    $tenant = Tenant::factory()->withDomain('revsubmit')->create();
    tenancy()->initialize($tenant);
    $vehicle = Vehicle::factory()->create();
    $customer = Customer::factory()->create();
    $booking = Booking::factory()->forVehicle($vehicle)->completed()->create([
        'customer_id' => $customer->id,
        'customer_name' => 'Arta Krasniqi',
        'customer_email' => 'arta@example.com',
    ]);
    tenancy()->end();

    URL::forceRootUrl(tenant_url('revsubmit'));
    $url = URL::temporarySignedRoute('public.booking.review', now()->addDays(30), ['booking' => $booking->id]);
    $this->get($url)->assertOk()->assertSee($vehicle->name);

    tenancy()->initialize($tenant);
    Livewire::test('pages::public.booking-review', ['booking' => $booking])
        ->set('rating', 4)
        ->set('comment', 'Great car, smooth ride.')
        ->call('submit')
        ->assertSet('submitted', true)
        ->assertHasNoErrors();

    $review = Review::query()->first();
    expect($review)->not->toBeNull()
        ->and($review->is_approved)->toBeFalse()
        ->and($review->rating)->toBe(4)
        ->and($review->reviewer_name)->toBe('Arta Krasniqi')
        ->and($review->booking_id)->toBe($booking->id)
        ->and((int) $review->vehicle_id)->toBe((int) $vehicle->id)
        ->and((int) $review->customer_id)->toBe((int) $customer->id)
        ->and($review->tenant_id)->toBe($tenant->id);
});

it('blocks a second review for the same booking (one per booking)', function () {
    $tenant = Tenant::factory()->withDomain('revdup')->create();
    tenancy()->initialize($tenant);
    $vehicle = Vehicle::factory()->create();
    $booking = Booking::factory()->forVehicle($vehicle)->completed()->create(['customer_email' => 'x@example.com']);
    Review::factory()->create(['booking_id' => $booking->id, 'vehicle_id' => $vehicle->id]);

    Livewire::test('pages::public.booking-review', ['booking' => $booking])
        ->assertSet('alreadyReviewed', true)
        ->set('rating', 5)
        ->call('submit');

    expect(Review::query()->where('booking_id', $booking->id)->count())->toBe(1);
});

it('shows the unavailable state for a non-completed booking', function () {
    $tenant = Tenant::factory()->withDomain('revpending')->create();
    tenancy()->initialize($tenant);
    $vehicle = Vehicle::factory()->create();
    $booking = Booking::factory()->forVehicle($vehicle)->confirmed()->create();

    Livewire::test('pages::public.booking-review', ['booking' => $booking])
        ->assertSet('unavailable', true);
});

it('returns 404 for a tampered or expired signature', function () {
    $tenant = Tenant::factory()->withDomain('revsig')->create();
    tenancy()->initialize($tenant);
    $vehicle = Vehicle::factory()->create();
    $booking = Booking::factory()->forVehicle($vehicle)->completed()->create();
    tenancy()->end();

    URL::forceRootUrl(tenant_url('revsig'));

    $tampered = URL::temporarySignedRoute('public.booking.review', now()->addDays(30), ['booking' => $booking->id]).'x';
    $this->get($tampered)->assertNotFound();

    $expired = URL::temporarySignedRoute('public.booking.review', now()->subMinute(), ['booking' => $booking->id]);
    $this->get($expired)->assertNotFound();
});

// ── Operator moderation resource ─────────────────────────────────────────────

it('gates the review resource to the owner only, and never by plan', function () {
    // Plan disables Reviews — the moderation resource must still be reachable.
    [$tenant] = reviewTenant('revgate', [PlanFeature::Reviews->value => false], 'revoff');
    expect(ReviewResource::canAccess())->toBeTrue();

    $staff = User::factory()->staff()->create(['tenant_id' => $tenant->id]);
    actingAs($staff);
    expect(ReviewResource::canAccess())->toBeFalse();
});

it('approve and hide actions flip is_approved', function () {
    reviewTenant('revmod');
    $vehicle = Vehicle::factory()->create();
    $booking = Booking::factory()->forVehicle($vehicle)->completed()->create();
    $review = Review::factory()->create(['booking_id' => $booking->id, 'vehicle_id' => $vehicle->id]);

    Livewire::test(ListReviews::class)
        ->callTableAction('approve', $review);
    expect($review->fresh()->is_approved)->toBeTrue();

    Livewire::test(ListReviews::class)
        ->callTableAction('hide', $review);
    expect($review->fresh()->is_approved)->toBeFalse();
});

// ── Public display ───────────────────────────────────────────────────────────

it('shows only approved reviews and the correct average on the vehicle page', function () {
    $tenant = Tenant::factory()->withDomain('revshow')->create();
    tenancy()->initialize($tenant);
    $vehicle = Vehicle::factory()->create(['is_public' => true]);

    $b1 = Booking::factory()->forVehicle($vehicle)->completed()->create();
    $b2 = Booking::factory()->forVehicle($vehicle)->completed()->create();
    $b3 = Booking::factory()->forVehicle($vehicle)->completed()->create();
    Review::factory()->approved()->create(['booking_id' => $b1->id, 'vehicle_id' => $vehicle->id, 'rating' => 4, 'comment' => 'Approved good']);
    Review::factory()->approved()->create(['booking_id' => $b2->id, 'vehicle_id' => $vehicle->id, 'rating' => 2, 'comment' => 'Approved meh']);
    Review::factory()->create(['booking_id' => $b3->id, 'vehicle_id' => $vehicle->id, 'rating' => 5, 'comment' => 'Hidden pending']);
    tenancy()->end();

    $this->get(tenant_url('revshow', "/vehicles/{$vehicle->id}"))
        ->assertOk()
        ->assertSee('Approved good')
        ->assertSee('Approved meh')
        ->assertDontSee('Hidden pending')
        ->assertSee('3.0'); // (4 + 2) / 2 = 3.0
});

// ── Home showcase (gated) ────────────────────────────────────────────────────

it('renders the home showcase only when the plan enables Reviews', function () {
    // Enabled plan → showcase visible.
    $on = Tenant::factory()->withDomain('revhomeon')->create(['plan' => 'revhomeon']);
    Plan::factory()->create(['slug' => 'revhomeon', 'features' => [PlanFeature::Reviews->value => true]]);
    tenancy()->initialize($on);
    $v = Vehicle::factory()->create(['is_public' => true]);
    $b = Booking::factory()->forVehicle($v)->completed()->create();
    Review::factory()->approved()->create(['booking_id' => $b->id, 'vehicle_id' => $v->id, 'comment' => 'Showcase star']);
    tenancy()->end();

    $this->get(tenant_url('revhomeon', '/'))->assertOk()->assertSee('Showcase star');

    // Disabled plan → per-vehicle display stays free, but no home showcase.
    $off = Tenant::factory()->withDomain('revhomeoff')->create(['plan' => 'revhomeoff']);
    Plan::factory()->create(['slug' => 'revhomeoff', 'features' => [PlanFeature::Reviews->value => false]]);
    tenancy()->initialize($off);
    $v2 = Vehicle::factory()->create(['is_public' => true]);
    $b2 = Booking::factory()->forVehicle($v2)->completed()->create();
    Review::factory()->approved()->create(['booking_id' => $b2->id, 'vehicle_id' => $v2->id, 'comment' => 'Hidden showcase']);
    tenancy()->end();

    $this->get(tenant_url('revhomeoff', '/'))->assertOk()->assertDontSee('Hidden showcase');
});

// ── Auto-request sweep ───────────────────────────────────────────────────────

it('queues one review request per eligible booking and does not resend on rerun', function () {
    Mail::fake();

    [$tenant] = reviewTenant('revsweep', [PlanFeature::Reviews->value => true], 'revsweepplan');
    $vehicle = Vehicle::factory()->create();
    $booking = Booking::factory()->forVehicle($vehicle)->completed()->create([
        'completed_at' => now()->subDay(),
        'customer_email' => 'sweep@example.com',
    ]);

    (new RequestReviewsJob($tenant))->handle();

    Mail::assertQueued(BookingReviewRequestMail::class, fn ($m) => $m->booking->is($booking));
    Mail::assertQueued(BookingReviewRequestMail::class, 1);
    expect($booking->fresh()->review_requested_at)->not->toBeNull();

    Mail::fake();
    (new RequestReviewsJob($tenant))->handle();
    Mail::assertNothingQueued();
});

it('does not re-check the plan inside the review job — the command is the only gate', function () {
    Mail::fake();

    [$tenant] = reviewTenant('revjobdirect', [PlanFeature::Reviews->value => false], 'revoffjob');
    $vehicle = Vehicle::factory()->create();
    $booking = Booking::factory()->forVehicle($vehicle)->completed()->create([
        'completed_at' => now()->subDay(),
        'customer_email' => 'direct@example.com',
    ]);

    (new RequestReviewsJob($tenant))->handle();

    // KNOWN GAP, pinned deliberately — same shape as the maintenance job: only
    // the reviews:request-pending command checks the plan, so a job already
    // queued when a tenant is downgraded still emails the customer. Narrow (the
    // dispatch window), but real. A guard here would fail this test,
    // which is the point: make the change conscious.
    Mail::assertQueued(BookingReviewRequestMail::class);
    expect($booking->fresh()->review_requested_at)->not->toBeNull();
});

it('does not request a review for a booking with no email or already reviewed', function () {
    Mail::fake();

    [$tenant] = reviewTenant('revsweepskip', [PlanFeature::Reviews->value => true], 'revskipplan');
    $vehicle = Vehicle::factory()->create();

    // No email → skipped.
    Booking::factory()->forVehicle($vehicle)->completed()->create([
        'completed_at' => now()->subDay(),
        'customer_email' => null,
    ]);
    // Already reviewed → skipped.
    $reviewed = Booking::factory()->forVehicle($vehicle)->completed()->create([
        'completed_at' => now()->subDay(),
        'customer_email' => 'has@example.com',
    ]);
    Review::factory()->create(['booking_id' => $reviewed->id, 'vehicle_id' => $vehicle->id]);

    (new RequestReviewsJob($tenant))->handle();

    Mail::assertNothingQueued();
});

it('dispatches the sweep job only for active tenants with Reviews enabled', function () {
    Queue::fake();

    Plan::factory()->create(['slug' => 'rev-on', 'features' => [PlanFeature::Reviews->value => true]]);
    Plan::factory()->create(['slug' => 'rev-no', 'features' => [PlanFeature::Reviews->value => false]]);

    Tenant::factory()->withDomain('revenabled')->create(['plan' => 'rev-on']);
    Tenant::factory()->withDomain('revdisabled')->create(['plan' => 'rev-no']);
    Tenant::factory()->withDomain('revsusp')->suspended()->create(['plan' => 'rev-on']);

    $this->artisan('reviews:request-pending')->assertSuccessful();

    Queue::assertPushed(RequestReviewsJob::class, 1);
});

it('configures retries and timeout for review sweep failures', function () {
    $job = new RequestReviewsJob(Tenant::factory()->make());

    expect($job->tries)->toBe(3)
        ->and($job->timeout)->toBe(60)
        ->and($job->backoff())->toBe([60, 300, 900]);
});

it('logs tenant context when the review sweep job fails permanently', function () {
    $tenant = Tenant::factory()->create();

    Log::spy();

    (new RequestReviewsJob($tenant))->failed(new Exception('boom'));

    Log::shouldHaveReceived('error')->once()->withArgs(
        fn (string $message, array $context) => $message === 'Review request sweep failed'
            && $context['tenant_id'] === $tenant->id
    );
});
