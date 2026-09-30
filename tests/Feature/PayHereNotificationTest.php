<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServicePackage;
use App\Models\ServiceProvider;
use App\Models\User;
use App\Services\Payments\PayHerePaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PayHereNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_documented_notification_signature_is_accepted(): void
    {
        $this->assertTrue(app(PayHerePaymentGateway::class)->verifyNotification($this->notification()));
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

    public function test_verified_success_confirms_booking_and_duplicate_is_a_safe_no_op(): void
    {
        [, $booking, $payment] = $this->createPaymentFixture('success');
        $payload = $this->notification(['order_id' => $payment->payment_reference, 'payment_id' => 'PAYHERE-success']);
        $this->post('/api/payments/payhere/notify', $payload)->assertOk()->assertJson(['message' => 'Notification acknowledged.']);
        $payment = $payment->fresh();
        $booking = $booking->fresh();
        $paidAt = $payment->paid_at;
        $confirmedAt = $booking->confirmed_at;
        $this->assertSame('paid', $payment->status);
        $this->assertSame('PAYHERE-success', $payment->gateway_transaction_id);
        $this->assertNotNull($paidAt);
        $this->assertSame('confirmed', $booking->booking_status);
        $this->assertSame('paid', $booking->payment_status);
        $this->assertNotNull($confirmedAt);
        $this->post('/api/payments/payhere/notify', $payload)->assertOk();
        $this->assertEquals($paidAt, $payment->fresh()->paid_at);
        $this->assertEquals($confirmedAt, $booking->fresh()->confirmed_at);
    }

    public function test_verified_pending_is_non_destructive_and_binds_its_transaction_identity(): void
    {
        [, $booking, $payment] = $this->createPaymentFixture('pending');
        $this->post('/api/payments/payhere/notify', $this->notification(['order_id' => $payment->payment_reference, 'payment_id' => 'PAYHERE-pending', 'status_code' => '0']))->assertOk();
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'pending', 'gateway_transaction_id' => 'PAYHERE-pending']);
        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'booking_status' => 'accepted', 'payment_status' => 'pending']);
    }

    public function test_verified_cancellation_and_failure_allow_normal_payment_retries(): void
    {
        $this->assertPaymentRetry('-1', 'cancelled');
    }

    public function test_verified_failure_allows_normal_payment_retry(): void
    {
        $this->assertPaymentRetry('-2', 'failed');
    }

    public function test_verified_chargeback_preserves_confirmed_and_completed_bookings(): void
    {
        foreach (['confirmed'] as $bookingStatus) {
            $this->flushHeaders();
            [, $booking, $payment] = $this->createPaymentFixture('chargeback-'.$bookingStatus, 'paid', $bookingStatus, 'paid');
            $payment->update(['gateway_transaction_id' => 'PAYHERE-'.$bookingStatus, 'paid_at' => now()->subMinute()]);
            $booking->update(['confirmed_at' => $bookingStatus === 'confirmed' ? now()->subMinute() : null, 'completed_at' => $bookingStatus === 'completed' ? now()->subMinute() : null]);
            $confirmedAt = $booking->fresh()->confirmed_at;
            $completedAt = $booking->fresh()->completed_at;
            $paidAt = $payment->fresh()->paid_at;
            $payload = $this->notification(['order_id' => $payment->payment_reference, 'payment_id' => 'PAYHERE-'.$bookingStatus, 'status_code' => '-3']);
            $this->post('/api/payments/payhere/notify', $payload)->assertOk();
            $payment = $payment->fresh();
            $booking = $booking->fresh();
            $chargedBackAt = $payment->charged_back_at;
            $this->assertSame('chargedback', $payment->status);
            $this->assertEquals($paidAt, $payment->paid_at);
            $this->assertNotNull($chargedBackAt);
            $this->assertSame($bookingStatus, $booking->booking_status);
            $this->assertSame('paid', $booking->payment_status);
            $this->assertEquals($confirmedAt, $booking->confirmed_at);
            $this->assertEquals($completedAt, $booking->completed_at);
        }
    }

    public function test_verified_chargeback_preserves_completed_booking(): void
    {
        [, $booking, $payment] = $this->createPaymentFixture('chargeback-completed', 'paid', 'completed', 'paid');
        $payment->update(['gateway_transaction_id' => 'PAYHERE-completed', 'paid_at' => now()->subMinute()]);
        $booking->update(['completed_at' => now()->subMinute()]);
        $paidAt = $payment->fresh()->paid_at;
        $completedAt = $booking->fresh()->completed_at;

        $this->post('/api/payments/payhere/notify', $this->notification([
            'order_id' => $payment->payment_reference,
            'payment_id' => 'PAYHERE-completed',
            'status_code' => '-3',
        ]))->assertOk();

        $this->assertSame('chargedback', $payment->fresh()->status);
        $this->assertEquals($paidAt, $payment->fresh()->paid_at);
        $this->assertNotNull($payment->fresh()->charged_back_at);
        $this->assertSame('completed', $booking->fresh()->booking_status);
        $this->assertSame('paid', $booking->fresh()->payment_status);
        $this->assertEquals($completedAt, $booking->fresh()->completed_at);
    }

    public function test_invalid_and_mismatched_callbacks_do_not_mutate_payments(): void
    {
        [, , $payment] = $this->createPaymentFixture('security');
        $this->post('/api/payments/payhere/notify', $this->notification(['order_id' => $payment->payment_reference, 'md5sig' => str_repeat('0', 32)]))->assertForbidden();
        $this->post('/api/payments/payhere/notify', $this->notification(['order_id' => $payment->payment_reference, 'merchant_id' => 'wrong-merchant']))->assertForbidden();
        foreach ([['payhere_amount' => '99.00'], ['payhere_currency' => 'USD']] as $overrides) {
            $this->post('/api/payments/payhere/notify', $this->notification(['order_id' => $payment->payment_reference, ...$overrides]))->assertOk();
        }
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'pending', 'gateway_transaction_id' => null]);
    }

    public function test_unknown_non_payhere_collision_and_different_transaction_replays_do_not_mutate(): void
    {
        [, , $payment] = $this->createPaymentFixture('transaction');
        [, , $collision] = $this->createPaymentFixture('collision');
        $collision->update(['gateway_transaction_id' => 'PAYHERE-collision']);
        $this->post('/api/payments/payhere/notify', $this->notification(['order_id' => 'PAY-UNKNOWN']))->assertNotFound();
        $nonPayHere = $payment->replicate();
        $nonPayHere->payment_reference = 'PAY-NON-PAYHERE';
        $nonPayHere->gateway = null;
        $nonPayHere->save();
        $this->post('/api/payments/payhere/notify', $this->notification(['order_id' => $nonPayHere->payment_reference]))->assertNotFound();
        $this->post('/api/payments/payhere/notify', $this->notification(['order_id' => $payment->payment_reference, 'payment_id' => 'PAYHERE-collision']))->assertOk();
        $this->assertSame('pending', $payment->fresh()->status);
        $success = $this->notification(['order_id' => $payment->payment_reference, 'payment_id' => 'PAYHERE-original']);
        $this->post('/api/payments/payhere/notify', $success)->assertOk();
        $this->post('/api/payments/payhere/notify', $this->notification(['order_id' => $payment->payment_reference, 'payment_id' => 'PAYHERE-different']))->assertOk();
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'paid', 'gateway_transaction_id' => 'PAYHERE-original']);
    }

    public function test_late_non_success_and_success_after_chargeback_do_not_regress_lifecycle(): void
    {
        [, , $payment] = $this->createPaymentFixture('late', 'paid', 'confirmed', 'paid');
        $payment->update(['gateway_transaction_id' => 'PAYHERE-late', 'paid_at' => now()->subMinute()]);
        foreach (['0', '-1', '-2'] as $statusCode) {
            $this->post('/api/payments/payhere/notify', $this->notification(['order_id' => $payment->payment_reference, 'payment_id' => 'PAYHERE-late', 'status_code' => $statusCode]))->assertOk();
        }
        $this->assertSame('paid', $payment->fresh()->status);
        $this->post('/api/payments/payhere/notify', $this->notification(['order_id' => $payment->payment_reference, 'payment_id' => 'PAYHERE-late', 'status_code' => '-3']))->assertOk();
        $this->post('/api/payments/payhere/notify', $this->notification(['order_id' => $payment->payment_reference, 'payment_id' => 'PAYHERE-late', 'status_code' => '2']))->assertOk();
        $this->assertSame('chargedback', $payment->fresh()->status);
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['customer', 'service_provider', 'admin'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }
        config([
            'services.payhere.mode' => 'sandbox', 'services.payhere.merchant_id' => 'test-merchant', 'services.payhere.merchant_secret' => 'test-secret',
            'services.payhere.return_url' => 'https://example.test/return', 'services.payhere.cancel_url' => 'https://example.test/cancel',
            'services.payhere.notify_url' => 'https://example.test/notify', 'services.payhere.currency' => 'LKR',
        ]);
    }

    /** @return array{0: User, 1: Booking, 2: Payment} */
    private function createPaymentFixture(string $suffix, string $paymentStatus = 'pending', string $bookingStatus = 'accepted', string $bookingPaymentStatus = 'pending'): array
    {
        $customer = User::factory()->create(['email' => $suffix.'-customer@example.test']);
        $customer->assignRole('customer');
        $providerUser = User::factory()->create(['email' => $suffix.'-provider@example.test']);
        $providerUser->assignRole('service_provider');
        $provider = ServiceProvider::create(['user_id' => $providerUser->id, 'business_name' => 'Provider '.$suffix, 'business_slug' => 'provider-'.$suffix, 'verification_status' => 'verified', 'is_active' => true]);
        $category = ServiceCategory::create(['name' => 'Category '.$suffix, 'slug' => 'category-'.$suffix]);
        $service = Service::create(['service_provider_id' => $provider->id, 'service_category_id' => $category->id, 'name' => 'Service '.$suffix, 'slug' => 'service-'.$suffix, 'status' => 'published']);
        $package = ServicePackage::create(['service_id' => $service->id, 'name' => 'Package '.$suffix, 'slug' => 'package-'.$suffix, 'price' => 100, 'status' => 'published']);
        $booking = Booking::create(['booking_reference' => 'BK-'.$suffix, 'customer_id' => $customer->id, 'service_provider_id' => $provider->id, 'service_id' => $service->id, 'service_package_id' => $package->id, 'event_date' => now()->addDays(3)->toDateString(), 'start_time' => '10:00', 'end_time' => '11:00', 'event_location' => 'Test Location', 'total_amount' => 100, 'booking_status' => $bookingStatus, 'payment_status' => $bookingPaymentStatus]);
        $payment = Payment::create(['booking_id' => $booking->id, 'customer_id' => $customer->id, 'payment_reference' => 'PAY-'.$suffix, 'amount' => 100, 'currency' => 'LKR', 'payment_method' => 'card', 'status' => $paymentStatus, 'gateway' => 'payhere']);
        return [$customer, $booking, $payment];
    }

    private function makePayHereReady(User $customer): void
    {
        $customer->customerProfile()->create(['first_name' => 'Test', 'last_name' => 'Customer', 'phone' => '+94770000000', 'address' => '1 Test Road', 'city' => 'Colombo', 'country' => 'Sri Lanka']);
    }

    private function assertPaymentRetry(string $statusCode, string $expectedStatus): void
    {
        [$customer, $booking, $payment] = $this->createPaymentFixture('retry-'.$expectedStatus);
        $this->post('/api/payments/payhere/notify', $this->notification([
            'order_id' => $payment->payment_reference,
            'payment_id' => 'PAYHERE-'.$expectedStatus,
            'status_code' => $statusCode,
            'status_message' => '<b>Gateway '.$expectedStatus.'</b>',
        ]))->assertOk();
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => $expectedStatus, 'gateway_transaction_id' => 'PAYHERE-'.$expectedStatus]);
        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'booking_status' => 'accepted', 'payment_status' => 'unpaid']);
        $this->makePayHereReady($customer);
        $token = $customer->createToken('customer-token')->plainTextToken;
        $this->withToken($token)
            ->postJson('/api/customer/bookings/'.$booking->id.'/payments', ['payment_method' => 'card'])
            ->assertCreated();
    }

    /** @return array<string, string> */
    private function notification(array $overrides = []): array
    {
        $payload = array_merge(['merchant_id' => 'test-merchant', 'order_id' => 'PAY-TEST-001', 'payment_id' => 'PAYHERE-TEST-123', 'payhere_amount' => '100.00', 'payhere_currency' => 'LKR', 'status_code' => '2'], $overrides);
        if (! array_key_exists('md5sig', $overrides)) {
            $payload['md5sig'] = strtoupper(md5($payload['merchant_id'].$payload['order_id'].$payload['payhere_amount'].$payload['payhere_currency'].$payload['status_code'].strtoupper(md5('test-secret'))));
        }
        return $payload;
    }
}
