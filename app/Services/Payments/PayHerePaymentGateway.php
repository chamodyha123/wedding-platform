<?php

namespace App\Services\Payments;

use App\Models\CustomerProfile;
use App\Models\Payment;
use RuntimeException;

class PayHerePaymentGateway
{
    /**
     * @return array{checkout_url: string, payload: array<string, string>}
     */
    public function checkout(Payment $payment, CustomerProfile $profile): array
    {
        $settings = $this->settings();
        $mode = $settings['mode'];
        $amount = number_format((float) $payment->amount, 2, '.', '');
        $currency = $settings['currency'];
        $orderId = $payment->payment_reference;
        $secretHash = strtoupper(md5($settings['merchant_secret']));
        $hash = strtoupper(md5($settings['merchant_id'].$orderId.$amount.$currency.$secretHash));

        return [
            'checkout_url' => $mode === 'sandbox' ? 'https://sandbox.payhere.lk/pay/checkout' : 'https://www.payhere.lk/pay/checkout',
            'payload' => [
                'merchant_id' => $settings['merchant_id'], 'return_url' => $settings['return_url'], 'cancel_url' => $settings['cancel_url'], 'notify_url' => $settings['notify_url'], 'first_name' => $profile->first_name, 'last_name' => $profile->last_name, 'email' => $payment->customer->email, 'phone' => $profile->phone, 'address' => $profile->address, 'city' => $profile->city, 'country' => $profile->country, 'order_id' => $orderId, 'items' => 'Booking '.$payment->booking->booking_reference, 'currency' => $currency, 'amount' => $amount, 'hash' => $hash,
            ],
        ];
    }

    /** @return array<string, string> */
    public function settings(): array
    {
        /** @var array<string, mixed> $settings */
        $settings = config('services.payhere');
        $mode = $settings['mode'] ?? null;

        if (! in_array($mode, ['sandbox', 'live'], true)) {
            throw new RuntimeException('PayHere is not configured.');
        }

        foreach (['merchant_id', 'merchant_secret', 'return_url', 'cancel_url', 'notify_url', 'currency'] as $key) {
            if (empty($settings[$key])) {
                throw new RuntimeException('PayHere is not configured.');
            }
        }

        return array_map('strval', $settings);
    }
}
