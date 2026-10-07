<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BookingStatus;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * A rent-a-car customer, auto-created/linked by phone whenever a booking is made. Tenant-scoped via BelongsToTenant — each
 * operator only ever sees their own customers.
 *
 * `phone` is the identity key (customers.[tenant_id, phone] is unique) and is
 * stored normalized — see App\Support\PhoneNumber. Every site that matches a
 * customer by phone must normalize first, or the same person becomes two records
 * and a promo code's per_customer_limit can be spent twice.
 *
 * `is_blacklisted` BLOCKS a public storefront booking (BookingService::create()
 * throws CustomerNotEligibleException). It deliberately does not block
 * createManual() — the operator may still book a flagged customer knowingly.
 *
 * @property bool $is_blacklisted
 */
#[Fillable(['name', 'phone', 'email', 'notes', 'is_blacklisted'])]
class Customer extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_blacklisted' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * Lifetime spend: only bookings that represent realised revenue (Active +
     * Completed), matching the revenue definition used on the Reports page.
     */
    public function totalSpend(): float
    {
        return (float) $this->bookings()
            ->whereIn('status', [BookingStatus::Active, BookingStatus::Completed])
            ->sum('total');
    }
}
