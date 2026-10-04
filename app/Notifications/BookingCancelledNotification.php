<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class BookingCancelledNotification extends Notification
{
    use Queueable;

    public function __construct(
        private int $bookingId,
        private string $bookingReference,
        private string $eventDate,
        private ?string $cancellationReason
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'booking_cancelled',
            'booking_id' => $this->bookingId,
            'booking_reference' => $this->bookingReference,
            'event_date' => $this->eventDate,
            'cancellation_reason' => $this->cancellationReason,
            'message' => 'A customer has cancelled a booking.',
        ];
    }
}