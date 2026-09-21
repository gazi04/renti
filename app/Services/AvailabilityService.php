<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\Vehicle;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

class AvailabilityService
{
    public function isAvailable(Vehicle $vehicle, CarbonInterface $start, CarbonInterface $end, ?int $excludeBookingId = null): bool
    {
        throw_unless($start->lt($end), InvalidArgumentException::class, 'start_date must be before end_date.');

        if ($this->hasBookingConflict($vehicle, $start, $end, $excludeBookingId)) {
            return false;
        }

        $overlaps = fn (Builder $q) => $q->where('start_date', '<', $end)->where('end_date', '>', $start);

        return ! $vehicle->blockedDates()->where($overlaps)->exists();
    }

    /**
     * Whether an occupying booking (Pending/Confirmed/Active) overlaps the given
     * half-open range on this vehicle. Shared by isAvailable() and the operator
     * block-dates guard so the interval predicate lives in exactly one place.
     *
     * $excludeBookingId lets a booking being moved check availability without
     * conflicting with its own current row (BookingService::move()) — every other
     * caller leaves it null and behaves exactly as before.
     */
    public function hasBookingConflict(Vehicle $vehicle, CarbonInterface $start, CarbonInterface $end, ?int $excludeBookingId = null): bool
    {
        return $vehicle->bookings()
            ->whereIn('status', BookingStatus::blocking())
            ->where('start_date', '<', $end)
            ->where('end_date', '>', $start)
            ->when($excludeBookingId, fn (Builder $q, int $id) => $q->where('id', '!=', $id))
            ->exists();
    }
}
