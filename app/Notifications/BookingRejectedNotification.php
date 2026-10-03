<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class BookingRejectedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private int $bookingId,
        private string $bookingReference,
        private string $serviceName,
        private ?string $providerNotes
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'booking_rejected',
            'booking_id' => $this->bookingId,
            'booking_reference' => $this->bookingReference,
            'service_name' => $this->serviceName,
            'provider_notes' => $this->providerNotes,
            'message' => 'Your booking request has been rejected.',
        ];
    }
}