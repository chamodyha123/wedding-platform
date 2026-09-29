<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Services\Payments\PayHerePaymentGateway;
use Tests\TestCase;

class PayHereNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_documented_notification_signature_is_accepted(): void
    {
        $payload = $this->notification();

        $this->assertTrue(app(PayHerePaymentGateway::class)->verifyNotification($payload));
    }

    public function test_tampered_signed_values_and_missing_configuration_fail_closed(): void
    {
        $payload = $this->notification();
        $gateway = app(PayHerePaymentGateway::class);

        foreach (['merchant_id', 'order_id', 'payhere_amount', 'payhere_currency', 'status_code'] as $key) {
            $tampered = $payload;
            $tampered[$key] .= 'x';
            $this->assertFalse($gateway->verifyNotification($tampered));
        }

        config(['services.payhere.merchant_secret' => null]);
        $this->assertFalse($gateway->verifyNotification($payload));
    }

    public function test_public_callback_rejects_malformed_invalid_and_unknown_callbacks_without_data_leakage(): void
    {
        $this->get('/api/payments/payhere/notify')->assertMethodNotAllowed();
        $this->withHeader('Accept', 'application/json')
            ->post('/api/payments/payhere/notify', [])
            ->assertUnprocessable();

        $invalid = $this->notification(['md5sig' => str_repeat('0', 32)]);
        $this->post('/api/payments/payhere/notify', $invalid)
            ->assertForbidden()
            ->assertJson(['message' => 'Notification rejected.'])
            ->assertJsonMissingPath('payment')
            ->assertJsonMissingPath('merchant_secret');

        $this->post('/api/payments/payhere/notify', $this->notification())
            ->assertNotFound()
            ->assertJson(['message' => 'Notification rejected.']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.payhere.merchant_id' => 'test-merchant',
            'services.payhere.merchant_secret' => 'test-secret',
        ]);
    }

    /** @return array<string, string> */
    private function notification(array $overrides = []): array
    {
        $payload = array_merge([
            'merchant_id' => 'test-merchant',
            'order_id' => 'PAY-TEST-001',
            'payment_id' => 'PAYHERE-TEST-123',
            'payhere_amount' => '100.00',
            'payhere_currency' => 'LKR',
            'status_code' => '2',
        ], $overrides);

        if (! array_key_exists('md5sig', $overrides)) {
            $payload['md5sig'] = strtoupper(md5(
                $payload['merchant_id'].$payload['order_id'].$payload['payhere_amount'].$payload['payhere_currency'].$payload['status_code'].strtoupper(md5('test-secret'))
            ));
        }

        return $payload;
    }
}
