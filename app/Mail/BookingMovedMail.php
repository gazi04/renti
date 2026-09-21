<?php

namespace App\Mail;

use App\Concerns\ThrottlesMailQueue;
use App\Models\Booking;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells a customer their booking's dates/vehicle changed (deep-audit finding
 * 08 — operator "move booking"). Not wired into the custom-template system
 * (config/templates.php) — that's scoped to the 4 original booking events;
 * adding a 5th is a deliberate follow-up, not bundled into this fix.
 */
class BookingMovedMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;
    use ThrottlesMailQueue;

    public function __construct(public readonly Booking $booking) {}

    public function envelope(): Envelope
    {
        $tenant = Tenant::query()->findOrFail($this->booking->tenant_id);

        return new Envelope(
            from: $tenant->senderAddress(),
            replyTo: array_filter([$tenant->replyToAddress()]),
            subject: __('emails.booking_moved.subject', ['reference' => $this->booking->reference]),
        );
    }

    public function content(): Content
    {
        $operator = Tenant::query()->findOrFail($this->booking->tenant_id)->name;

        return new Content(
            markdown: 'emails.booking-moved',
            with: [
                'booking' => $this->booking,
                'operator' => $operator,
            ],
        );
    }
}
