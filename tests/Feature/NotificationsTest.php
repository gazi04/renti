<?php

use App\Events\BookingCancelled;
use App\Events\BookingConfirmed;
use App\Events\BookingCreated;
use App\Events\BookingMoved;
use App\Events\BookingRejected;
use App\Listeners\SendBookingCancelledNotifications;
use App\Listeners\SendBookingConfirmedEmail;
use App\Listeners\SendBookingMovedNotifications;
use App\Listeners\SendBookingReceivedNotifications;
use App\Listeners\SendBookingRejectedEmail;
use App\Mail\BookingCancelledMail;
use App\Mail\BookingConfirmedMail;
use App\Mail\BookingMovedMail;
use App\Mail\BookingReceivedMail;
use App\Mail\BookingRejectedMail;
use App\Mail\BookingReviewRequestMail;
use App\Mail\NewBookingAlertMail;
use App\Models\Booking;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\BookingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    tenancy()->initialize($this->tenant);
    $this->operator = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'operator']);
    $this->vehicle = Vehicle::factory()->create(['daily_rate' => 50]);
    $this->service = app(BookingService::class);
});

afterEach(fn () => tenancy()->end());

/** @return array<string, mixed> */
function notifBookingData(Vehicle $vehicle, array $overrides = []): array
{
    return array_merge([
        'vehicle_id' => $vehicle->id,
        'customer_name' => 'Ana Kelmendi',
        'customer_phone' => '+38344000001',
        'customer_email' => 'ana@example.com',
        'start_date' => '2031-07-01',
        'end_date' => '2031-07-04',
    ], $overrides);
}

// ── Public booking (BookingCreated) ─────────────────────────────────────────

test('public booking queues customer received mail and operator alert mail', function () {
    Mail::fake();

    $booking = $this->service->create(notifBookingData($this->vehicle));

    $listener = new SendBookingReceivedNotifications;
    $listener->handle(new BookingCreated($booking));

    Mail::assertQueued(BookingReceivedMail::class, fn ($m) => $m->hasTo($booking->customer_email));
    Mail::assertQueued(NewBookingAlertMail::class, fn ($m) => $m->hasTo($this->operator->email));
});

test('customer mail is sent from the platform address, with the operator as reply-to', function () {
    // Resend (and any real ESP) 403s a From on a domain it hasn't verified.
    // Operators sign up with whatever inbox they already have (gmail.com,
    // hotmail.com, ...), so the platform's own verified domain must be the
    // From address on every customer-facing mail — the operator's address
    // only ever belongs on Reply-To.
    Mail::fake();

    $booking = $this->service->create(notifBookingData($this->vehicle));

    $listener = new SendBookingReceivedNotifications;
    $listener->handle(new BookingCreated($booking));

    Mail::assertQueued(BookingReceivedMail::class, function ($m) {
        return $m->hasFrom(config()->string('mail.from.address'))
            && $m->hasReplyTo($this->tenant->email);
    });
});

test('public booking listener sends operator dashboard bell notification', function () {
    Mail::fake();

    $booking = $this->service->create(notifBookingData($this->vehicle));

    $listener = new SendBookingReceivedNotifications;
    $listener->handle(new BookingCreated($booking));

    $this->assertDatabaseHas('notifications', [
        'notifiable_id' => $this->operator->id,
        'notifiable_type' => User::class,
    ]);
});

test('manual booking does not queue customer received mail', function () {
    Mail::fake();

    $booking = $this->service->createManual(notifBookingData($this->vehicle));

    Mail::assertNothingQueued();
    expect($booking->status->value)->toBe('confirmed');
});

test('no customer mail queued when customer_email is null', function () {
    Mail::fake();

    $booking = $this->service->create(notifBookingData($this->vehicle, ['customer_email' => null]));

    $listener = new SendBookingReceivedNotifications;
    $listener->handle(new BookingCreated($booking));

    Mail::assertNotQueued(BookingReceivedMail::class);
    Mail::assertQueued(NewBookingAlertMail::class);
});

// ── Confirm (BookingConfirmed) ───────────────────────────────────────────────

test('confirm queues booking confirmed mail to customer', function () {
    Mail::fake();

    $booking = Booking::factory()->create([
        'vehicle_id' => $this->vehicle->id,
        'customer_email' => 'ana@example.com',
        'locale' => 'en',
    ]);

    $listener = new SendBookingConfirmedEmail;
    $listener->handle(new BookingConfirmed($booking));

    Mail::assertQueued(BookingConfirmedMail::class, fn ($m) => $m->hasTo($booking->customer_email)
        && $m->envelope()->subject === "Booking Confirmed — {$booking->reference}"
    );
});

test('confirm still queues mail and generates the agreement when the vehicle was soft-deleted', function () {
    Storage::fake();
    Mail::fake();

    $booking = Booking::factory()->create([
        'vehicle_id' => $this->vehicle->id,
        'customer_email' => 'ana@example.com',
        'locale' => 'en',
    ]);
    $this->vehicle->delete();

    $listener = new SendBookingConfirmedEmail;
    $listener->handle(new BookingConfirmed($booking->fresh()));

    Mail::assertQueued(BookingConfirmedMail::class, fn ($m) => $m->hasTo($booking->customer_email));
});

test('confirm dispatches BookingConfirmed event via service', function () {
    $booking = Booking::factory()->create(['vehicle_id' => $this->vehicle->id]);

    Event::fake([BookingConfirmed::class]);

    $this->service->confirm($booking);

    Event::assertDispatched(BookingConfirmed::class, fn ($e) => $e->booking->is($booking));
});

// ── Reject (BookingRejected) ─────────────────────────────────────────────────

test('reject queues booking rejected mail to customer', function () {
    Mail::fake();

    $booking = Booking::factory()->create([
        'vehicle_id' => $this->vehicle->id,
        'customer_email' => 'ana@example.com',
        'locale' => 'sq',
    ]);

    $listener = new SendBookingRejectedEmail;
    $listener->handle(new BookingRejected($booking));

    Mail::assertQueued(BookingRejectedMail::class, fn ($m) => $m->hasTo($booking->customer_email));
});

test('reject dispatches BookingRejected event via service', function () {
    $booking = Booking::factory()->create(['vehicle_id' => $this->vehicle->id]);

    Event::fake([BookingRejected::class]);

    $this->service->reject($booking);

    Event::assertDispatched(BookingRejected::class);
});

// ── Cancel by operator ───────────────────────────────────────────────────────

test('cancel by operator emails customer but not operator bell', function () {
    Mail::fake();

    $booking = Booking::factory()->create([
        'vehicle_id' => $this->vehicle->id,
        'customer_email' => 'ana@example.com',
        'locale' => 'en',
    ]);

    $notificationsBefore = $this->operator->notifications()->count();

    $listener = new SendBookingCancelledNotifications;
    $listener->handle(new BookingCancelled($booking, 'operator'));

    Mail::assertQueued(BookingCancelledMail::class, fn ($m) => $m->hasTo($booking->customer_email));
    expect($this->operator->notifications()->count())->toBe($notificationsBefore);
});

test('cancel by customer emails customer and operator and sends bell', function () {
    Mail::fake();

    $booking = Booking::factory()->create([
        'vehicle_id' => $this->vehicle->id,
        'customer_email' => 'ana@example.com',
        'locale' => 'en',
    ]);

    $listener = new SendBookingCancelledNotifications;
    $listener->handle(new BookingCancelled($booking, 'customer'));

    Mail::assertQueued(BookingCancelledMail::class, fn ($m) => $m->hasTo($booking->customer_email));
    Mail::assertQueued(BookingCancelledMail::class, fn ($m) => $m->hasTo($this->operator->email));
    $this->assertDatabaseHas('notifications', [
        'notifiable_id' => $this->operator->id,
        'notifiable_type' => User::class,
    ]);
});

test('cancel dispatches BookingCancelled event with cancelledBy actor via service', function () {
    $booking = Booking::factory()->create(['vehicle_id' => $this->vehicle->id]);

    Event::fake([BookingCancelled::class]);

    $this->service->cancel($booking, cancelledBy: 'customer');

    Event::assertDispatched(BookingCancelled::class, fn ($e) => $e->cancelledBy === 'customer');
});

// ── Move (deep-audit finding 08) ────────────────────────────────────────────────

test('move emails the customer and sends the operator a bell notification', function () {
    Mail::fake();

    $booking = Booking::factory()->create([
        'vehicle_id' => $this->vehicle->id,
        'customer_email' => 'ana@example.com',
        'locale' => 'en',
    ]);

    $notificationsBefore = $this->operator->notifications()->count();

    $listener = new SendBookingMovedNotifications;
    $listener->handle(new BookingMoved($booking));

    Mail::assertQueued(BookingMovedMail::class, fn ($m) => $m->hasTo($booking->customer_email));
    // Operator made the change themselves — bell only, no self-email (same
    // reasoning as an operator-initiated cancel).
    Mail::assertNotQueued(BookingMovedMail::class, fn ($m) => $m->hasTo($this->operator->email));
    expect($this->operator->notifications()->count())->toBe($notificationsBefore + 1);
});

test('move sends no mail when the booking has no customer email', function () {
    Mail::fake();

    $booking = Booking::factory()->create([
        'vehicle_id' => $this->vehicle->id,
        'customer_email' => null,
    ]);

    $listener = new SendBookingMovedNotifications;
    $listener->handle(new BookingMoved($booking));

    Mail::assertNothingQueued();
    expect($this->operator->notifications()->count())->toBe(1);
});

test('move mailable and listener are queued', function () {
    expect(BookingMovedMail::class)->toImplement(ShouldQueue::class)
        ->and(SendBookingMovedNotifications::class)->toImplement(ShouldQueue::class);
});

// ── Operator locale (BUG-L10) ────────────────────────────────────────────────

test('new booking alert falls back to sq when the operator has no saved locale', function () {
    Mail::fake();

    expect($this->operator->locale)->toBeNull();

    $booking = $this->service->create(notifBookingData($this->vehicle));

    $listener = new SendBookingReceivedNotifications;
    $listener->handle(new BookingCreated($booking));

    Mail::assertQueued(NewBookingAlertMail::class, fn ($m) => $m->hasTo($this->operator->email) && $m->locale === 'sq');
});

test('new booking alert uses the operator\'s own saved locale', function () {
    Mail::fake();

    $this->operator->forceFill(['locale' => 'en'])->save();

    $booking = $this->service->create(notifBookingData($this->vehicle));

    $listener = new SendBookingReceivedNotifications;
    $listener->handle(new BookingCreated($booking));

    Mail::assertQueued(NewBookingAlertMail::class, fn ($m) => $m->hasTo($this->operator->email) && $m->locale === 'en');
});

test('cancel-by-customer operator mail falls back to sq when the operator has no saved locale', function () {
    Mail::fake();

    expect($this->operator->locale)->toBeNull();

    $booking = Booking::factory()->create(['vehicle_id' => $this->vehicle->id]);

    $listener = new SendBookingCancelledNotifications;
    $listener->handle(new BookingCancelled($booking, 'customer'));

    Mail::assertQueued(BookingCancelledMail::class, fn ($m) => $m->hasTo($this->operator->email) && $m->locale === 'sq');
});

test('cancel-by-customer operator mail uses the operator\'s own saved locale', function () {
    Mail::fake();

    $this->operator->forceFill(['locale' => 'en'])->save();

    $booking = Booking::factory()->create(['vehicle_id' => $this->vehicle->id]);

    $listener = new SendBookingCancelledNotifications;
    $listener->handle(new BookingCancelled($booking, 'customer'));

    Mail::assertQueued(BookingCancelledMail::class, fn ($m) => $m->hasTo($this->operator->email) && $m->locale === 'en');
});

// ── Bilingual ────────────────────────────────────────────────────────────────

test('booking received mailable stores booking locale sq', function () {
    $booking = Booking::factory()->create([
        'vehicle_id' => $this->vehicle->id,
        'locale' => 'sq',
    ]);

    $mailable = new BookingReceivedMail($booking);

    expect($mailable->booking->locale)->toBe('sq');
});

test('booking confirmed mailable stores booking locale en', function () {
    $booking = Booking::factory()->create([
        'vehicle_id' => $this->vehicle->id,
        'locale' => 'en',
    ]);

    $mailable = new BookingConfirmedMail($booking);

    expect($mailable->booking->locale)->toBe('en');
});

test('booking confirmed email renders an absolute logo URL, not a relative one', function () {
    Storage::fake('public');

    $this->tenant->addMedia(UploadedFile::fake()->image('logo.png', 200, 200))
        ->toMediaCollection('logo');

    $booking = Booking::factory()->create(['vehicle_id' => $this->vehicle->id]);

    $rendered = (new BookingConfirmedMail($booking))->render();

    expect($rendered)->toContain('src="'.rtrim(config('app.url'), '/'))
        ->and($rendered)->not->toContain('src="/storage/');
});

test('every customer-facing booking email renders the operator logo and primary color', function () {
    Storage::fake('public');

    $this->tenant->addMedia(UploadedFile::fake()->image('logo.png', 200, 200))
        ->toMediaCollection('logo');
    $this->tenant->setSetting('color_primary', '#ff5500');

    $booking = Booking::factory()->create(['vehicle_id' => $this->vehicle->id]);

    $mailables = [
        new BookingReceivedMail($booking),
        new BookingConfirmedMail($booking),
        new BookingRejectedMail($booking),
        new BookingCancelledMail($booking),
        BookingReviewRequestMail::forTenantDomain($booking),
    ];

    foreach ($mailables as $mailable) {
        $rendered = $mailable->render();

        expect($rendered)->toContain('src="'.rtrim(config('app.url'), '/'))
            ->and($rendered)->not->toContain('src="/storage/')
            ->and($rendered)->toContain('color: #ff5500');
    }
});

test('booking emails fall back to the default primary color when unbranded', function () {
    $booking = Booking::factory()->create(['vehicle_id' => $this->vehicle->id]);

    $rendered = (new BookingReceivedMail($booking))->render();

    expect($rendered)->toContain('color: '.config('branding.defaults.color_primary'));
});

test('cancelled/rejected emails show the operator-entered reason when present', function () {
    $booking = Booking::factory()->create([
        'vehicle_id' => $this->vehicle->id,
        'cancellation_reason' => 'Vehicle was in an accident and is unavailable.',
    ]);

    foreach ([new BookingCancelledMail($booking), new BookingRejectedMail($booking)] as $mailable) {
        expect($mailable->render())->toContain('Vehicle was in an accident and is unavailable.');
    }
});

test('cancelled/rejected emails omit the reason row when none was given', function () {
    $booking = Booking::factory()->create([
        'vehicle_id' => $this->vehicle->id,
        'cancellation_reason' => null,
    ]);

    expect((new BookingCancelledMail($booking))->render())->not->toContain(__('emails.booking_cancelled.reason_label'))
        ->and((new BookingRejectedMail($booking))->render())->not->toContain(__('emails.booking_rejected.reason_label'));
});

// ── Queued, not sync ─────────────────────────────────────────────────────────

test('all mailables implement ShouldQueue', function () {
    expect(BookingReceivedMail::class)->toImplement(ShouldQueue::class);
    expect(NewBookingAlertMail::class)->toImplement(ShouldQueue::class);
    expect(BookingConfirmedMail::class)->toImplement(ShouldQueue::class);
    expect(BookingRejectedMail::class)->toImplement(ShouldQueue::class);
    expect(BookingCancelledMail::class)->toImplement(ShouldQueue::class);
});

test('all listeners implement ShouldQueue', function () {
    expect(SendBookingReceivedNotifications::class)->toImplement(ShouldQueue::class);
    expect(SendBookingConfirmedEmail::class)->toImplement(ShouldQueue::class);
    expect(SendBookingRejectedEmail::class)->toImplement(ShouldQueue::class);
    expect(SendBookingCancelledNotifications::class)->toImplement(ShouldQueue::class);
});

// ── Tenant isolation ─────────────────────────────────────────────────────────

test('operator alert goes only to this tenants users not another tenants users', function () {
    Mail::fake();

    $otherTenant = Tenant::factory()->create();
    $otherOperator = User::factory()->create(['tenant_id' => $otherTenant->id, 'role' => 'operator']);

    $booking = $this->service->create(notifBookingData($this->vehicle));

    $listener = new SendBookingReceivedNotifications;
    $listener->handle(new BookingCreated($booking));

    Mail::assertQueued(NewBookingAlertMail::class, fn ($m) => $m->hasTo($this->operator->email));
    Mail::assertNotQueued(NewBookingAlertMail::class, fn ($m) => $m->hasTo($otherOperator->email));
});

// ── Signed-URL root derivation (M9) ──────────────────────────────────────────

test('signed cancel link follows app.url scheme and port on the tenant domain', function () {
    tenancy()->end();

    config(['app.url' => 'https://platform.test:8443']);

    $tenant = Tenant::factory()->withDomain('m9tenant')->create();
    tenancy()->initialize($tenant);
    $vehicle = Vehicle::factory()->create(['daily_rate' => 50]);
    $booking = app(BookingService::class)->create(notifBookingData($vehicle));

    expect($tenant->publicRootUrl())->toBe('https://'.tenant_domain('m9tenant').':8443');

    $mail = BookingReceivedMail::forTenantDomain($booking);

    expect($mail->cancelUrl)->toStartWith('https://'.tenant_domain('m9tenant').':8443/')
        ->and($mail->cancelUrl)->toContain('signature=');
});

// ── Cancel-link lifetime (deep-audit finding 07) ──────────────────────────────

test('received-email cancel link is signed until the rental starts, not a fixed window', function () {
    tenancy()->end();

    $tenant = Tenant::factory()->withDomain('cancellifetime')->create();
    tenancy()->initialize($tenant);
    $vehicle = Vehicle::factory()->create(['daily_rate' => 50]);

    $booking = app(BookingService::class)->create(notifBookingData($vehicle, [
        'customer_email' => 'ana@example.com',
        // Well past bookings.pending_expiry_hours (48h default) — a link tied
        // to that window would already be dead by the time this booking's
        // rental even starts.
        'start_date' => now()->addWeeks(3)->toDateString(),
        'end_date' => now()->addWeeks(3)->addDays(3)->toDateString(),
    ]));

    $mail = BookingReceivedMail::forTenantDomain($booking);

    expect($mail->cancelUrl)->not->toBeNull();

    parse_str((string) parse_url($mail->cancelUrl, PHP_URL_QUERY), $query);

    expect((int) $query['expires'])->toBe($booking->start_date->getTimestamp());
});

test('confirmed-email now carries a signed cancel link, not just the agreement download', function () {
    tenancy()->end();

    $tenant = Tenant::factory()->withDomain('confirmcancellink')->create();
    tenancy()->initialize($tenant);
    $vehicle = Vehicle::factory()->create(['daily_rate' => 50]);

    Mail::fake();

    $booking = Booking::factory()->create([
        'vehicle_id' => $vehicle->id,
        'customer_email' => 'ana@example.com',
        'start_date' => now()->addWeeks(2),
        'locale' => 'en',
    ]);

    (new SendBookingConfirmedEmail)->handle(new BookingConfirmed($booking));

    Mail::assertQueued(BookingConfirmedMail::class, fn (BookingConfirmedMail $mail): bool => $mail->cancelUrl !== null
        && str_contains($mail->cancelUrl, 'signature=')
    );
});

// ── Envelope robustness: nullable tenant.email, missing tenant row ───────────

test('customer email still sends from the platform address when the tenant has no email', function () {
    config(['mail.from.address' => 'noreply@platform.test']);

    $this->tenant->update(['email' => null]);

    $booking = $this->service->create(notifBookingData($this->vehicle));

    $envelope = (new BookingReceivedMail($booking))->envelope();

    expect($envelope->from?->address)->toBe('noreply@platform.test')
        ->and($envelope->from?->name)->toBe($this->tenant->name);
});

test('a mailable whose tenant row is gone fails loudly instead of reading a property on null', function () {
    $booking = $this->service->create(notifBookingData($this->vehicle));

    tenancy()->end();
    Tenant::query()->whereKey($this->tenant->id)->delete();

    expect(fn () => (new BookingReceivedMail($booking))->envelope())
        ->toThrow(ModelNotFoundException::class);
});
