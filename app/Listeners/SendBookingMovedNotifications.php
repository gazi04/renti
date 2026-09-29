<?php

namespace App\Listeners;

use App\Events\BookingMoved;
use App\Mail\BookingMovedMail;
use App\Models\Tenant;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

class SendBookingMovedNotifications implements ShouldQueue
{
    public function handle(BookingMoved $event): void
    {
        $booking = $event->booking;

        if (filled($booking->customer_email)) {
            $tenant = Tenant::query()->find($booking->tenant_id);

            // isSelfCancellable() is already guaranteed by move()'s own status
            // guard, but this listener is queued — by the time it runs, a
            // fast follow-up cancel/expire could in principle have landed
            // first. Same non-re-locking guard SendBookingConfirmedEmail uses.
            $cancelUrl = $booking->isSelfCancellable()
                ? $tenant?->signedRouteUrl('public.booking.cancel', $booking->start_date, ['booking' => $booking->id])
                : null;

            Mail::to($booking->customer_email)
                ->locale($booking->locale ?? 'sq')
                ->queue(new BookingMovedMail($booking, $cancelUrl));
        }

        // Operator-initiated (this pass has no customer self-service path), so
        // only the bell — no email to the person who just made the change
        // themselves, same reasoning as cancel()'s "an operator cancelling needs
        // no telling".
        $operators = User::query()->where('tenant_id', $booking->tenant_id)->get();

        foreach ($operators as $operator) {
            Notification::make()
                ->title(__('panel.booking_moved_bell_title'))
                ->body($booking->customer_name.' · '.$booking->vehicle->name)
                ->success()
                ->sendToDatabase($operator);
        }
    }
}
