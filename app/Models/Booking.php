<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BookingStatus;
use App\Enums\RateType;
use Carbon\CarbonImmutable;
use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * @property BookingStatus $status
 * @property RateType $rate_type
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable $end_date
 * @property CarbonImmutable|null $previous_start_date
 * @property CarbonImmutable|null $previous_end_date
 * @property CarbonImmutable|null $moved_at
 * @property-read Vehicle|null $previousVehicle
 */
#[Fillable(['vehicle_id', 'customer_id', 'promo_code_id', 'reference', 'customer_name', 'customer_phone', 'customer_email', 'pickup_location', 'notes', 'start_date', 'end_date', 'rate_type', 'subtotal', 'discount_amount', 'total', 'deposit', 'status', 'locale', 'started_at', 'completed_at', 'review_requested_at', 'start_odometer', 'end_odometer'])]
class Booking extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<BookingFactory> */
    use HasFactory;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'status' => BookingStatus::class,
            'rate_type' => RateType::class,
            'start_date' => 'datetime',
            'end_date' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'review_requested_at' => 'datetime',
            'previous_start_date' => 'datetime',
            'previous_end_date' => 'datetime',
            'moved_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'deposit' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withTrashed();
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function previousVehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'previous_vehicle_id')->withTrashed();
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<PromoCode, $this> */
    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }

    /** @return HasOne<Contract, $this> */
    public function contract(): HasOne
    {
        return $this->hasOne(Contract::class);
    }

    /** @return HasOne<Review, $this> */
    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }

    /**
     * Whether a customer can self-cancel this booking via the signed link —
     * Pending or Confirmed only. Active means the car is already with them (a
     * self-service "cancel" means nothing at that point); Completed/Cancelled
     * are handled by the separate alreadyDone branch in both cancel
     * controllers. Single source of truth so ShowCancelBookingController and
     * CancelBookingController can't drift.
     */
    public function isSelfCancellable(): bool
    {
        return in_array($this->status, [BookingStatus::Pending, BookingStatus::Confirmed], true);
    }

    /**
     * Whether an operator may move this booking to new dates/vehicle
     * (BookingService::move()) — same Pending/Confirmed pair as
     * isSelfCancellable(): Active has already locked in odometer/timestamp
     * reality, Completed/Cancelled are settled.
     */
    public function isMovable(): bool
    {
        return in_array($this->status, [BookingStatus::Pending, BookingStatus::Confirmed], true);
    }
}
