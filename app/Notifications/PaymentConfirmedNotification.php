<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class PaymentConfirmedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private int $paymentId,
        private string $paymentReference,
        private int $bookingId,
        private string $bookingReference,
        private string $amount,
        private string $currency
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'payment_confirmed',
            'payment_id' => $this->paymentId,
            'payment_reference' => $this->paymentReference,
            'booking_id' => $this->bookingId,
            'booking_reference' => $this->bookingReference,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'message' => 'Your payment has been confirmed.',
        ];
    }
}