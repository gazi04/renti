<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Tenant;
use App\Services\BookingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cancels one tenant's stale Pending bookings (deep-audit finding 01).
 *
 * A Pending booking occupies its vehicle — BookingStatus::blocking() counts it
 * alongside Confirmed and Active — and until this sweep existed nothing ever
 * released one: every exit from Pending was a human action. An unanswered
 * booking therefore held a car off the market forever.
 *
 * Two rules, both of which mean the booking can no longer become a real rental:
 * it has waited longer than config('bookings.pending_expiry_hours'), or its
 * pickup day has already ended while still unconfirmed.
 *
 * Dispatched from the central bookings:expire-pending command, so the
 * QueueTenancyBootstrapper does NOT re-initialize tenancy for us — the job
 * initializes (and always ends) tenancy itself.
 */
class ExpireStalePendingBookingsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(private readonly Tenant $tenant) {}

    public function handle(BookingService $bookings): void
    {
        tenancy()->initialize($this->tenant);

        try {
            $cutoff = now()->subHours(Config::integer('bookings.pending_expiry_hours'));

            Booking::query()
                ->where('status', BookingStatus::Pending)
                ->where(fn (Builder $query) => $query
                    ->where('created_at', '<', $cutoff)
                    // SQL form of Booking::pickupDeadline()->isPast(): the
                    // pickup day ended before today began. Not `<= now()` —
                    // start_date is date-only (00:00), which expired same-day
                    // bookings within the hour.
                    ->orWhere('start_date', '<', today()))
                ->each(function (Booking $booking) use ($bookings): void {
                    $bookings->expire($booking, $this->expiryReason($booking));
                });
        } finally {
            tenancy()->end();
        }
    }

    /**
     * The reason is stored on the booking and rendered verbatim into the
     * customer's cancellation email, so it has to be translated here — into the
     * language the customer booked in, not the queue worker's locale.
     */
    private function expiryReason(Booking $booking): string
    {
        return __('emails.booking_cancelled.expired_reason', [], $booking->locale ?? $this->tenant->operatorLocale());
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Pending booking expiry sweep failed', [
            'tenant_id' => $this->tenant->id,
            'exception' => $exception->getMessage(),
        ]);
    }
}
