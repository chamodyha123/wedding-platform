<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class BookingCompletedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private int $bookingId,
        private string $bookingReference,
        private string $serviceName,
        private string $completedAt
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'booking_completed',
            'booking_id' => $this->bookingId,
            'booking_reference' => $this->bookingReference,
            'service_name' => $this->serviceName,
            'completed_at' => $this->completedAt,
            'message' => 'Your booking has been marked as completed.',
        ];
    }
}