<?php

namespace Tests\Unit;

use App\Http\Resources\PaymentResource;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Service;
use App\Models\ServicePackage;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class PaymentResourceTest extends TestCase
{
    public function test_gateway_metadata_is_not_exposed(): void
    {
        $payment = new Payment([
            'id' => 1,
            'booking_id' => 2,
            'payment_reference' => 'PAY-TEST-001',
            'amount' => '125000.00',
            'currency' => 'LKR',
            'payment_method' => 'card',
            'status' => 'paid',
            'metadata' => ['gateway_response' => 'internal'],
            'gateway_transaction_id' => 'gateway-secret',
        ]);

        $booking = new Booking([
            'id' => 2,
            'booking_reference' => 'BK-TEST-001',
            'customer_id' => 3,
            'total_amount' => '125000.00',
            'booking_status' => 'confirmed',
            'payment_status' => 'paid',
        ]);
        $booking->setRelation('service', new Service([
            'id' => 4,
            'name' => 'Photography',
            'slug' => 'photography',
        ]));
        $booking->setRelation('package', new ServicePackage([
            'id' => 5,
            'name' => 'Full Day',
            'slug' => 'full-day',
            'price' => '125000.00',
            'duration_minutes' => 480,
        ]));
        $payment->setRelation('booking', $booking);

        $resource = (new PaymentResource($payment))
            ->toArray(Request::create('/'));

        $this->assertArrayNotHasKey('metadata', $resource);
        $this->assertArrayNotHasKey('gateway_transaction_id', $resource);
        $this->assertSame('PAY-TEST-001', $resource['payment_reference']);
        $this->assertSame('Photography', $resource['booking']['service']['name']);
    }
}
