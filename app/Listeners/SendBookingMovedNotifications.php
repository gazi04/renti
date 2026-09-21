<?php

namespace App\Listeners;

use App\Events\BookingMoved;
use App\Mail\BookingMovedMail;
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
            Mail::to($booking->customer_email)
                ->locale($booking->locale ?? 'sq')
                ->queue(new BookingMovedMail($booking));
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
