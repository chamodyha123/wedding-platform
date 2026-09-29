<?php

namespace Tests\Feature;

use App\Models\Payment;
use Carbon\Carbon;
use Tests\TestCase;

class PaymentChargebackStateTest extends TestCase
{
    public function test_chargeback_timestamp_is_nullable_and_cast_as_datetime(): void
    {
        $payment = new Payment([
            'status' => 'chargedback',
        ]);

        $this->assertNull($payment->charged_back_at);
        $this->assertSame('chargedback', $payment->status);

        $payment->charged_back_at = '2026-09-29 12:00:00';

        $this->assertInstanceOf(Carbon::class, $payment->charged_back_at);
    }

    public function test_existing_and_chargeback_payment_states_remain_storable(): void
    {
        foreach ([
            'pending',
            'processing',
            'paid',
            'failed',
            'cancelled',
            'refunded',
            'chargedback',
        ] as $status) {
            $payment = new Payment([
                'status' => $status,
            ]);

            $this->assertSame($status, $payment->status);
        }
    }
}
