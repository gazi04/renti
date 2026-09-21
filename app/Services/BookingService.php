<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\PlanFeature;
use App\Enums\VehicleStatus;
use App\Events\BookingCancelled;
use App\Events\BookingConfirmed;
use App\Events\BookingCreated;
use App\Events\BookingMoved;
use App\Events\BookingRejected;
use App\Exceptions\CustomerNotEligibleException;
use App\Exceptions\InvalidBookingWindowException;
use App\Exceptions\PromoCodeInvalidException;
use App\Exceptions\VehicleNotAvailableException;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\PromoCode;
use App\Models\Tenant;
use App\Models\Vehicle;
use App\Support\BookingReference;
use App\Support\PhoneNumber;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class BookingService
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly PricingService $pricing,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data): Booking
    {
        return DB::transaction(function () use ($data) {
            [$vehicle, $start, $end] = $this->lockAndValidate($data);

            // trustContactDetails: false — every field here is unverified input
            // from the public wizard, so a visitor who types someone else's phone
            // number must not be able to rewrite that person's directory record.
            $customer = $this->resolveCustomer($data, trustContactDetails: false);

            // The blacklist is enforced on the public path only. An operator can
            // still book a flagged customer knowingly via createManual().
            if ($customer->is_blacklisted) {
                throw new CustomerNotEligibleException('This booking cannot be completed online.');
            }

            $promo = $this->resolvePromo($data, $customer);
            $price = $this->pricing->calculate($vehicle, $start, $end, $promo);

            $booking = Booking::query()->create([
                'reference' => $this->generateReference(),
                'vehicle_id' => $vehicle->id,
                'customer_id' => $customer->id,
                'promo_code_id' => $promo?->id,
                'customer_name' => $data['customer_name'],
                'customer_phone' => $data['customer_phone'],
                'customer_email' => $data['customer_email'] ?? null,
                'pickup_location' => $data['pickup_location'] ?? null,
                'notes' => $data['notes'] ?? null,
                'start_date' => $start,
                'end_date' => $end,
                'rate_type' => $price['rate_type'],
                'subtotal' => $price['subtotal'],
                'discount_amount' => $price['discount'],
                'total' => $price['total'],
                'deposit' => $price['deposit'],
                'status' => BookingStatus::Pending,
                'locale' => app()->getLocale(),
            ]);

            event(new BookingCreated($booking));

            return $booking;
        });
    }

    /**
     * Walk-in / phone booking: race-safe create that lands Confirmed.
     * Does not fire BookingCreated — no customer "received" email.
     *
     * @param  array<string, mixed>  $data
     */
    public function createManual(array $data): Booking
    {
        return DB::transaction(function () use ($data) {
            // allowPastStart: a front-desk operator records the walk-in who drove
            // off at 09:00 and is entered at 11:00. The duration cap still applies.
            // allowUnlisted: an operator may take a phone booking for a vehicle
            // deliberately kept off the public site (a VIP car, an off-storefront
            // arrangement) — that's the point of this path existing at all.
            [$vehicle, $start, $end] = $this->lockAndValidate($data, allowPastStart: true, allowUnlisted: true);

            // trustContactDetails: true — an authenticated operator typing at the
            // front desk IS the authority on their own directory, so a corrected
            // name or a newly given email should land on the existing record.
            $customer = $this->resolveCustomer($data, trustContactDetails: true);
            $promo = $this->resolvePromo($data, $customer);
            $price = $this->pricing->calculate($vehicle, $start, $end, $promo);

            return Booking::query()->create([
                'reference' => $this->generateReference(),
                'vehicle_id' => $vehicle->id,
                'customer_id' => $customer->id,
                'promo_code_id' => $promo?->id,
                'customer_name' => $data['customer_name'],
                'customer_phone' => $data['customer_phone'],
                'customer_email' => $data['customer_email'] ?? null,
                'pickup_location' => $data['pickup_location'] ?? null,
                'notes' => $data['notes'] ?? null,
                'start_date' => $start,
                'end_date' => $end,
                'rate_type' => $price['rate_type'],
                'subtotal' => $price['subtotal'],
                'discount_amount' => $price['discount'],
                'total' => $price['total'],
                'deposit' => $price['deposit'],
                'status' => BookingStatus::Confirmed,
                'locale' => app()->getLocale(),
            ]);
        });
    }

    public function confirm(Booking $booking): void
    {
        $this->transition($booking, BookingStatus::Pending, BookingStatus::Confirmed);
        event(new BookingConfirmed($booking));
    }

    public function reject(Booking $booking, ?string $reason = null): void
    {
        $this->transition($booking, BookingStatus::Pending, BookingStatus::Cancelled, [
            ...($reason !== null ? ['cancellation_reason' => $reason] : []),
        ]);
        event(new BookingRejected($booking));
    }

    public function markActive(Booking $booking, ?CarbonInterface $startedAt = null, ?int $startOdometer = null): void
    {
        $this->transition($booking, BookingStatus::Confirmed, BookingStatus::Active, [
            'started_at' => $startedAt ?? now(),
            ...($startOdometer !== null ? ['start_odometer' => $startOdometer] : []),
        ]);
    }

    public function complete(Booking $booking, ?CarbonInterface $completedAt = null, ?int $endOdometer = null): void
    {
        $this->transition($booking, BookingStatus::Active, BookingStatus::Completed, [
            'completed_at' => $completedAt ?? now(),
            ...($endOdometer !== null ? ['end_odometer' => $endOdometer] : []),
        ]);
    }

    public function cancel(Booking $booking, string $cancelledBy = 'operator', ?string $reason = null): void
    {
        throw_if($booking->status === BookingStatus::Completed, InvalidArgumentException::class, 'Completed bookings cannot be cancelled.');

        $updated = Booking::query()->whereKey($booking->getKey())
            ->where('status', '!=', BookingStatus::Cancelled->value)
            ->update([
                'status' => BookingStatus::Cancelled->value,
                ...($reason !== null ? ['cancellation_reason' => $reason] : []),
            ]);

        if ($updated === 0) {
            return;
        }

        $booking->refresh();
        event(new BookingCancelled($booking, $cancelledBy));
    }

    /**
     * Operator-side reschedule: change a booking's vehicle and/or dates while
     * keeping its reference, re-checking availability under the same vehicle
     * lock every create path uses (deep-audit finding 08 — cancel-and-rebook
     * lost the reference, re-priced at today's rate, and left a gap for someone
     * else to take the slot in between). Only Pending/Confirmed bookings are
     * movable — Active has already locked in a start odometer/timestamp and
     * Completed/Cancelled are settled; those need a different flow, not this one.
     *
     * Race-safe like transition(): the status guard rides in the same UPDATE as
     * the new vehicle/dates/pricing, so a concurrent cancel/reject landing
     * between our read and our write can't be silently overwritten.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException
     * @throws InvalidBookingWindowException
     * @throws VehicleNotAvailableException
     */
    public function move(Booking $booking, array $data): Booking
    {
        return DB::transaction(function () use ($booking, $data) {
            // Re-read the pre-image from the database rather than trusting the
            // caller's in-memory $booking — a Filament Action's schema fields
            // sharing names with model columns (vehicle_id/start_date/end_date
            // here) can leave the passed-in instance already carrying the
            // submitted values by the time this runs, which would otherwise
            // snapshot the NEW state as the "previous" one.
            $before = Booking::query()->whereKey($booking->getKey())->firstOrFail();

            [$vehicle, $start, $end] = $this->lockAndValidate($data, allowPastStart: true, allowUnlisted: true, excluding: $booking);

            $price = $this->pricing->calculate($vehicle, $start, $end, $before->promoCode);

            $updated = Booking::query()->whereKey($booking->getKey())
                ->whereIn('status', [BookingStatus::Pending->value, BookingStatus::Confirmed->value])
                ->update([
                    'previous_vehicle_id' => $before->vehicle_id,
                    'previous_start_date' => $before->start_date,
                    'previous_end_date' => $before->end_date,
                    'moved_at' => now(),
                    'vehicle_id' => $vehicle->id,
                    'start_date' => $start,
                    'end_date' => $end,
                    'rate_type' => $price['rate_type'],
                    'subtotal' => $price['subtotal'],
                    'discount_amount' => $price['discount'],
                    'total' => $price['total'],
                    'deposit' => $price['deposit'],
                ]);

            if ($updated === 0) {
                $booking->refresh();

                throw new InvalidArgumentException(
                    sprintf('Booking must be pending or confirmed to be moved, got %s.', $booking->status->value)
                );
            }

            $booking->refresh();

            // The PDF is idempotent by file existence, not by content — an
            // already-generated agreement must be force-regenerated or the next
            // download silently serves the pre-move dates.
            if ($booking->contract !== null) {
                resolve(RentalAgreementService::class)->generate($booking, force: true);
            }

            event(new BookingMoved($booking));

            return $booking;
        });
    }

    /**
     * Cancel a booking that has sat Pending too long, on behalf of the hourly
     * bookings:expire-pending sweep. Deliberately not cancel(): that one accepts
     * any non-Completed status, and the sweep must never undo a booking the
     * operator confirmed a moment ago. The Pending guard rides in the UPDATE, so
     * a sweep that loses that race simply reports false — unlike transition(),
     * which throws, and a background sweep has no one to throw at.
     *
     * @return bool Whether this call is the one that cancelled the booking.
     */
    public function expire(Booking $booking, string $reason): bool
    {
        $updated = Booking::query()->whereKey($booking->getKey())
            ->where('status', BookingStatus::Pending->value)
            ->update([
                'status' => BookingStatus::Cancelled->value,
                'cancellation_reason' => $reason,
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            return false;
        }

        $booking->refresh();
        event(new BookingCancelled($booking, 'system'));

        return true;
    }

    /**
     * Lock the vehicle row, parse dates, check the rental window, and re-check
     * availability. Must be called inside a DB::transaction. Pricing is computed
     * by the caller (after resolving any promo code).
     *
     * @param  bool  $allowPastStart  Operator-entered bookings may start in the past; customer ones may not.
     * @param  bool  $allowUnlisted  Operator-entered bookings may target a vehicle kept off the public site; customer ones may not.
     * @param  Booking|null  $excluding  A booking being moved must not conflict with its own current row — see move().
     * @param  array<string, mixed>  $data
     * @return array{0: Vehicle, 1: CarbonInterface, 2: CarbonInterface}
     *
     * @throws InvalidBookingWindowException
     * @throws VehicleNotAvailableException
     */
    private function lockAndValidate(array $data, bool $allowPastStart = false, bool $allowUnlisted = false, ?Booking $excluding = null): array
    {
        // Lock the vehicle row — concurrent transactions queue behind this.
        $vehicle = Vehicle::query()->whereKey($data['vehicle_id'])->lockForUpdate()->firstOrFail();

        $this->assertBookable($vehicle, $allowUnlisted);

        $start = Date::parse($data['start_date']);
        $end = Date::parse($data['end_date']);

        $this->assertBookableWindow($start, $end, $allowPastStart);

        try {
            $available = $this->availability->isAvailable($vehicle, $start, $end, $excluding?->id);
        } catch (InvalidArgumentException) {
            throw new VehicleNotAvailableException('The selected dates are invalid.');
        }

        // Re-check under the lock — the answer can't change until we commit.
        throw_unless($available, VehicleNotAvailableException::class, 'Sorry, this vehicle was just booked by someone else.');

        return [$vehicle, $start, $end];
    }

    /**
     * The vehicle itself must be bookable, independently of whether the dates
     * are free. mount() checks this once when the wizard page loads (deep-audit
     * finding 05); this is the re-check under the lock that makes it a
     * guarantee rather than a point-in-time snapshot an operator can
     * invalidate mid-request by pulling the vehicle for maintenance.
     *
     * @throws VehicleNotAvailableException
     */
    private function assertBookable(Vehicle $vehicle, bool $allowUnlisted): void
    {
        throw_if(
            $vehicle->status !== VehicleStatus::Available,
            VehicleNotAvailableException::class,
            'This vehicle is not available for booking.'
        );

        throw_if(
            ! $allowUnlisted && ! $vehicle->is_public,
            VehicleNotAvailableException::class,
            'This vehicle is not available for booking.'
        );
    }

    /**
     * The window itself must be bookable, independently of who else holds it.
     * The wizard's rules and flatpickr say the same thing, but both live in the
     * browser or in one component — this is the choke point every create path
     * shares, so it is where the invariant belongs.
     *
     * The app runs on UTC (config/app.php) while the market is UTC+1/+2, so a
     * customer's local "today" is never behind today() here: the floor can only
     * ever be permissive by a day, never reject a legitimate same-day booking.
     *
     * @throws InvalidBookingWindowException
     */
    private function assertBookableWindow(CarbonInterface $start, CarbonInterface $end, bool $allowPastStart): void
    {
        throw_if(
            ! $allowPastStart && $start->copy()->startOfDay()->lt(today()),
            InvalidBookingWindowException::class,
            'A booking cannot start in the past.'
        );

        $maxDays = Config::integer('bookings.max_rental_days');

        // Measured with the same helper that prices the rental, so the guard and
        // the invoice can never disagree about how long a booking is.
        throw_if(
            $start->lt($end) && $this->pricing->rentalDays($start, $end) > $maxDays,
            InvalidBookingWindowException::class,
            "A booking cannot run longer than {$maxDays} days."
        );
    }

    /**
     * Resolve and validate the promo code on the booking data (if any) for this
     * customer. Returns null when no code is given or the feature is off (the
     * code is silently ignored). Locks the promo row so a global usage cap can't
     * be exceeded by concurrent redemptions. Must run inside the transaction.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws PromoCodeInvalidException
     */
    private function resolvePromo(array $data, Customer $customer): ?PromoCode
    {
        $code = strtoupper(trim((string) ($data['promo_code'] ?? '')));

        if ($code === '' || ! (Tenant::current()?->allowsFeature(PlanFeature::PromoCodes) ?? (bool) PlanFeature::PromoCodes->default())) {
            return null;
        }

        $promo = PromoCode::query()->where('code', $code)->lockForUpdate()->first();

        if ($promo === null || ! $promo->isValidForCustomer($customer)) {
            throw new PromoCodeInvalidException('This promo code is not valid.');
        }

        return $promo;
    }

    /**
     * Race-safe state transition: a single conditional UPDATE guarded by the
     * expected current status, so two concurrent operators acting on the same
     * booking can never both succeed (the loser's affected-row count is 0).
     * Extra attributes ride in the same statement, keeping status + timestamps
     * atomic.
     *
     * @param  array<string, mixed>  $extra
     */
    private function transition(Booking $booking, BookingStatus $from, BookingStatus $to, array $extra = []): void
    {
        $updated = Booking::query()->whereKey($booking->getKey())
            ->where('status', $from->value)
            ->update([
                'status' => $to->value,
                'updated_at' => now(),
                ...$extra,
            ]);

        if ($updated === 0) {
            $booking->refresh();

            throw new InvalidArgumentException(
                sprintf('Booking must be %s to transition to %s, got %s.', $from->value, $to->value, $booking->status->value)
            );
        }

        $booking->refresh();
    }

    /**
     * Find-or-create the customer directory record for this booking, matched by
     * normalized phone within the current tenant (the customers.[tenant_id,
     * phone] unique key). Runs inside the booking transaction, under the vehicle
     * row lock, so every create path links a customer exactly once.
     *
     * $trustContactDetails decides whether an ALREADY EXISTING record may be
     * rewritten from this booking's data. It is false for public bookings: the
     * phone is unverified, so anyone could otherwise submit a booking under a
     * victim's number and replace that customer's stored name and email —
     * corrupting the directory the operator uses to recognise repeat and
     * blacklisted customers, and silently redirecting every "contact this
     * customer" action to an attacker's address.
     *
     * Nothing is lost by refusing: bookings.customer_name / customer_phone /
     * customer_email already carry what this visitor typed, so the operator can
     * still see the submitted details on the booking. The directory record stays
     * authoritative.
     *
     * A NULL email on an existing record is deliberately NOT backfilled from a
     * public booking either — a blank contact address is precisely the case
     * where an attacker supplying one takes ownership of it.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveCustomer(array $data, bool $trustContactDetails): Customer
    {
        $phone = PhoneNumber::normalize($data['customer_phone']);

        $customer = Customer::query()->firstOrCreate(
            ['phone' => $phone],
            ['name' => $data['customer_name'], 'email' => $data['customer_email'] ?? null],
        );

        if ($trustContactDetails && ! $customer->wasRecentlyCreated) {
            $customer->fill([
                'name' => $data['customer_name'],
                'email' => $data['customer_email'] ?? null,
            ])->save();
        }

        return $customer;
    }

    private function generateReference(): string
    {
        return BookingReference::generate();
    }
}
