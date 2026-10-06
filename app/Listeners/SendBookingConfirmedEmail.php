<?php

namespace App\Listeners;

use App\Events\BookingConfirmed;
use App\Mail\BookingConfirmedMail;
use App\Models\Tenant;
use App\Services\RentalAgreementService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

class SendBookingConfirmedEmail implements ShouldQueue
{
    public function handle(BookingConfirmed $event): void
    {
        $booking = $event->booking;

        if (blank($booking->customer_email)) {
            return;
        }

        resolve(RentalAgreementService::class)->generate($booking);

        $tenant = Tenant::query()->find($booking->tenant_id);

        $agreementUrl = $tenant?->signedRouteUrl(
            'agreement.download',
            now()->addDays(7),
            ['booking' => $booking->reference],
        );

        // Same deadline as the received email's link (Booking::isSelfCancellable()
        // covers Confirmed too) — a customer who only kept this later email
        // still finds a working cancel link, not just the original one.
        $cancelUrl = $tenant?->signedRouteUrl(
            'public.booking.cancel',
            $booking->pickupDeadline(),
            ['booking' => $booking->id],
        );

        Mail::to($booking->customer_email)
            ->locale($booking->locale ?? 'sq')
            ->queue(new BookingConfirmedMail($booking, $agreementUrl, $cancelUrl));
    }
}
