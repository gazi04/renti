<?php

namespace App\Mail;

use App\Concerns\ThrottlesMailQueue;
use App\Models\Booking;
use App\Models\Tenant;
use App\Services\TemplateRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BookingReceivedMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;
    use ThrottlesMailQueue;

    public function __construct(
        public readonly Booking $booking,
        public readonly ?string $cancelUrl = null,
    ) {}

    public static function forTenantDomain(Booking $booking): self
    {
        $cancelUrl = null;
        $tenant = Tenant::query()->find($booking->tenant_id);

        if ($tenant !== null && filled($booking->customer_email)) {
            $cancelUrl = $tenant->signedRouteUrl(
                'public.booking.cancel',
                // Once the rental would start, self-cancel no longer means
                // anything — same reasoning as Booking::isSelfCancellable(),
                // expressed as a deadline instead of a status. The whole pickup
                // day, so a same-day booking's link isn't dead on arrival.
                $booking->pickupDeadline(),
                ['booking' => $booking->id],
            );
        }

        return new self($booking, $cancelUrl);
    }

    public function envelope(): Envelope
    {
        $tenant = Tenant::query()->findOrFail($this->booking->tenant_id);

        return new Envelope(
            from: $tenant->senderAddress(),
            replyTo: array_filter([$tenant->replyToAddress()]),
            subject: resolve(TemplateRenderer::class)->resolve(
                $this->booking,
                'tmpl_email_received_subject',
                'emails.booking_received.subject',
                ['reference' => $this->booking->reference],
            ),
        );
    }

    public function content(): Content
    {
        $renderer = resolve(TemplateRenderer::class);
        $operator = Tenant::query()->findOrFail($this->booking->tenant_id)->name;

        return new Content(
            markdown: 'emails.booking-received',
            with: [
                'booking' => $this->booking,
                'cancelUrl' => $this->cancelUrl,
                'intro' => $renderer->resolve($this->booking, 'tmpl_email_received_intro', 'emails.booking_received.intro'),
                'outro' => $renderer->resolve($this->booking, 'tmpl_email_received_outro', 'emails.booking_received.outro', ['operator' => $operator]),
            ],
        );
    }
}
