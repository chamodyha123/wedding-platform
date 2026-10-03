<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class BookingCreatedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private int $bookingId,
        private string $bookingReference,
        private string $serviceName,
        private string $eventDate,
        private string $customerName
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'booking_created',
            'booking_id' => $this->bookingId,
            'booking_reference' => $this->bookingReference,
            'service_name' => $this->serviceName,
            'event_date' => $this->eventDate,
            'customer_name' => $this->customerName,
            'message' => 'You received a new booking request.',
        ];
    }
}