<?php

namespace App\Listeners;

use App\Enums\PlanFeature;
use App\Events\BookingCancelled;
use App\Events\BookingMoved;
use App\Events\BookingRejected;
use App\Models\Tenant;
use App\Models\Vehicle;
use App\Services\WaitlistService;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Tells the waitlist when a booking releases its dates (backlog #2).
 *
 * Three events matter and are easy to conflate: reject() dispatches
 * BookingRejected and cancel() dispatches BookingCancelled, but both land the
 * booking on Cancelled and both free the dates; move() dispatches BookingMoved
 * and frees the *previous* (vehicle, dates) combination instead of the current
 * one. Handling only some of these silently loses part of the free-ups. Laravel
 * discovers any public `handle*` method by its first-parameter type (see
 * DiscoverEvents), so the methods below are all registered — do NOT also
 * Event::listen() this, or every free-up notifies twice.
 *
 * Dispatched from tenant context, so QueueTenancyBootstrapper restores tenancy
 * for us — unlike the central-command jobs, this must not initialize it itself.
 */
class NotifyWaitlistOnBookingFreed implements ShouldQueue
{
    public function __construct(private readonly WaitlistService $waitlist) {}

    public function handleBookingCancelled(BookingCancelled $event): void
    {
        $booking = $event->booking;

        $this->notify($booking->tenant_id, $booking->vehicle, $booking->start_date, $booking->end_date);
    }

    public function handleBookingRejected(BookingRejected $event): void
    {
        $booking = $event->booking;

        $this->notify($booking->tenant_id, $booking->vehicle, $booking->start_date, $booking->end_date);
    }

    /**
     * A move frees the booking's *previous* vehicle/dates, not its current
     * ones. Guard against a false "it's available" notice: if the vehicle
     * didn't change and the new window still overlaps the old one (e.g.
     * extending or shrinking a rental on the same car), the old window isn't
     * actually free — the moved booking still occupies the overlapping part.
     */
    public function handleBookingMoved(BookingMoved $event): void
    {
        $booking = $event->booking;
        $previousStart = $booking->previous_start_date;
        $previousEnd = $booking->previous_end_date;

        if ($booking->previous_vehicle_id === null || $previousStart === null || $previousEnd === null) {
            return;
        }

        $sameVehicleStillOverlapping = $booking->previous_vehicle_id === $booking->vehicle_id
            && $previousStart->lt($booking->end_date)
            && $previousEnd->gt($booking->start_date);

        if ($sameVehicleStillOverlapping) {
            return;
        }

        $this->notify($booking->tenant_id, $booking->previousVehicle, $previousStart, $previousEnd);
    }

    private function notify(int $tenantId, ?Vehicle $vehicle, ?CarbonInterface $start, ?CarbonInterface $end): void
    {
        if (! $vehicle instanceof Vehicle) {
            return;
        }

        $tenant = Tenant::query()->find($tenantId);

        if ($tenant === null || ! $tenant->allowsFeature(PlanFeature::Waitlist)) {
            return;
        }

        $this->waitlist->notifyMatching($vehicle, $start, $end);
    }
}
