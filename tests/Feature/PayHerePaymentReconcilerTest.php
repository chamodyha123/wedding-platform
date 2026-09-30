<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServicePackage;
use App\Models\ServiceProvider;
use App\Models\User;
use App\Services\Payments\PayHerePaymentReconciler;
use App\Services\Payments\PayHereRetrievalClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PayHerePaymentReconcilerTest extends TestCase
{
    use RefreshDatabase;

    public function test_received_transitions_a_unique_unbound_payment_and_duplicate_preserves_timestamps(): void
    {
        [, $booking, $payment] = $this->fixture('received');
        $this->fakeRecords([$this->record($payment, 'RECEIVED', 'TX-1')]);
        $result = app(PayHerePaymentReconciler::class)->reconcile($payment);
        $paidAt = $payment->fresh()->paid_at;
        $confirmedAt = $booking->fresh()->confirmed_at;
        $this->assertSame('reconciled', $result['outcome']);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'paid', 'gateway_transaction_id' => 'TX-1']);
        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'booking_status' => 'confirmed', 'payment_status' => 'paid']);

        $this->fakeRecords([$this->record($payment->fresh(), 'RECEIVED', 'TX-1')]);
        $this->assertSame('already_current', app(PayHerePaymentReconciler::class)->reconcile($payment->fresh())['outcome']);
        $this->assertEquals($paidAt, $payment->fresh()->paid_at);
        $this->assertEquals($confirmedAt, $booking->fresh()->confirmed_at);
    }

    public function test_known_transaction_selects_one_matching_record_and_ambiguous_records_do_not_mutate(): void
    {
        [, , $payment] = $this->fixture('identity');
        $payment->update(['gateway_transaction_id' => 'TX-KEEP']);
        $this->fakeRecords([$this->record($payment, 'RECEIVED', 'TX-OTHER'), $this->record($payment, 'RECEIVED', 'TX-KEEP')]);
        $this->assertSame('reconciled', app(PayHerePaymentReconciler::class)->reconcile($payment->fresh())['outcome']);
        $this->assertSame('TX-KEEP', $payment->fresh()->gateway_transaction_id);

        [, $booking, $ambiguous] = $this->fixture('ambiguous');
        $this->fakeRecords([$this->record($ambiguous, 'RECEIVED', 'TX-A'), $this->record($ambiguous, 'RECEIVED', 'TX-B')]);
        $this->assertSame('ambiguous', app(PayHerePaymentReconciler::class)->reconcile($ambiguous)['outcome']);
        $this->assertUnchanged($ambiguous, $booking);
    }

    public function test_untrusted_records_do_not_mutate_payment_or_booking(): void
    {
        foreach ([
            'none' => [],
            'amount' => [['amount' => '99.99']],
            'currency' => [['currency' => 'USD']],
            'transaction' => [['payment_id' => 'TX-OTHER']],
        ] as $case => $overrides) {
            [, $booking, $payment] = $this->fixture('untrusted-'.$case);
            if ($case === 'transaction') {
                $payment->update(['gateway_transaction_id' => 'TX-KEEP']);
            }
            $records = $case === 'none' ? [] : array_map(fn (array $override): array => $this->record($payment->fresh(), 'RECEIVED', $override['payment_id'] ?? 'TX-1', $override), $overrides);
            $this->fakeRecords($records);
            $this->assertContains(app(PayHerePaymentReconciler::class)->reconcile($payment->fresh())['outcome'], ['no_matching_payment', 'already_current']);
            $this->assertUnchanged($payment, $booking);
        }
    }

    public function test_chargeback_and_duplicate_preserve_booking_and_completed_state(): void
    {
        [, $booking, $payment] = $this->fixture('chargeback', 'paid', 'completed', 'paid');
        $payment->update(['gateway_transaction_id' => 'TX-CB', 'paid_at' => now()->subMinute()]);
        $booking->update(['completed_at' => now()->subMinute()]);
        $paidAt = $payment->fresh()->paid_at;
        $completedAt = $booking->fresh()->completed_at;
        $this->fakeRecords([$this->record($payment->fresh(), 'CHARGEBACKED', 'TX-CB')]);
        $this->assertSame('reconciled', app(PayHerePaymentReconciler::class)->reconcile($payment->fresh())['outcome']);
        $chargedBackAt = $payment->fresh()->charged_back_at;
        $this->assertSame('chargedback', $payment->fresh()->status);
        $this->assertSame('completed', $booking->fresh()->booking_status);
        $this->assertEquals($paidAt, $payment->fresh()->paid_at);
        $this->assertEquals($completedAt, $booking->fresh()->completed_at);

        $this->fakeRecords([$this->record($payment->fresh(), 'CHARGEBACKED', 'TX-CB')]);
        $this->assertSame('already_current', app(PayHerePaymentReconciler::class)->reconcile($payment->fresh())['outcome']);
        $this->assertEquals($chargedBackAt, $payment->fresh()->charged_back_at);
    }

    public function test_refund_and_unknown_gateway_statuses_are_detection_only(): void
    {
        foreach (['REFUND REQUESTED', 'REFUND PROCESSING', 'REFUNDED', 'UNKNOWN'] as $status) {
            [, $booking, $payment] = $this->fixture('status-'.strtolower(str_replace(' ', '-', $status)));
            $this->fakeRecords([$this->record($payment, $status, 'TX-1')]);
            $result = app(PayHerePaymentReconciler::class)->reconcile($payment);
            $this->assertSame(str_starts_with($status, 'REFUND') ? 'refund_state_detected' : 'unsupported_status', $result['outcome']);
            $this->assertUnchanged($payment, $booking);
        }
    }

    public function test_retrieval_failure_and_non_payhere_payment_do_not_mutate(): void
    {
        [, $booking, $payment] = $this->fixture('failure');
        $this->fakeFailure();
        try {
            app(PayHerePaymentReconciler::class)->reconcile($payment);
            $this->fail('Expected retrieval failure.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
        $this->assertUnchanged($payment, $booking);

        $payment->update(['gateway' => null]);
        app()->instance(PayHereRetrievalClient::class, Mockery::mock(PayHereRetrievalClient::class));
        $this->assertSame('ineligible', app(PayHerePaymentReconciler::class)->reconcile($payment->fresh())['outcome']);
        $this->assertUnchanged($payment, $booking);
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['customer', 'service_provider'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @return array{0: User, 1: Booking, 2: Payment} */
    private function fixture(string $suffix, string $paymentStatus = 'pending', string $bookingStatus = 'accepted', string $bookingPaymentStatus = 'pending'): array
    {
        $customer = User::factory()->create(['email' => $suffix.'@example.test']);
        $customer->assignRole('customer');
        $providerUser = User::factory()->create();
        $providerUser->assignRole('service_provider');
        $provider = ServiceProvider::create(['user_id' => $providerUser->id, 'business_name' => 'Provider '.$suffix, 'business_slug' => 'provider-'.$suffix, 'verification_status' => 'verified', 'is_active' => true]);
        $category = ServiceCategory::create(['name' => 'Category '.$suffix, 'slug' => 'category-'.$suffix]);
        $service = Service::create(['service_provider_id' => $provider->id, 'service_category_id' => $category->id, 'name' => 'Service '.$suffix, 'slug' => 'service-'.$suffix, 'status' => 'published']);
        $package = ServicePackage::create(['service_id' => $service->id, 'name' => 'Package '.$suffix, 'slug' => 'package-'.$suffix, 'price' => 100, 'status' => 'published']);
        $booking = Booking::create(['booking_reference' => 'BK-'.$suffix, 'customer_id' => $customer->id, 'service_provider_id' => $provider->id, 'service_id' => $service->id, 'service_package_id' => $package->id, 'event_date' => now()->addDays(3)->toDateString(), 'start_time' => '10:00', 'end_time' => '11:00', 'event_location' => 'Test', 'total_amount' => 100, 'booking_status' => $bookingStatus, 'payment_status' => $bookingPaymentStatus]);
        $payment = Payment::create(['booking_id' => $booking->id, 'customer_id' => $customer->id, 'payment_reference' => 'PAY-'.$suffix, 'amount' => 100, 'currency' => 'LKR', 'payment_method' => 'card', 'status' => $paymentStatus, 'gateway' => 'payhere']);

        return [$customer, $booking, $payment];
    }

    /** @param list<array{order_id: string, payment_id: string, status: string, amount: string, currency: string}> $records */
    private function fakeRecords(array $records): void
    {
        app()->instance(PayHereRetrievalClient::class, Mockery::mock(PayHereRetrievalClient::class, fn ($mock) => $mock->shouldReceive('search')->once()->andReturn($records)));
    }

    private function fakeFailure(): void
    {
        app()->instance(PayHereRetrievalClient::class, Mockery::mock(PayHereRetrievalClient::class, fn ($mock) => $mock->shouldReceive('search')->once()->andThrow(new RuntimeException('Unavailable'))));
    }

    /** @param array<string, string> $overrides @return array{order_id: string, payment_id: string, status: string, amount: string, currency: string} */
    private function record(Payment $payment, string $status, string $transactionId, array $overrides = []): array
    {
        return array_replace(['order_id' => $payment->payment_reference, 'payment_id' => $transactionId, 'status' => $status, 'amount' => '100.00', 'currency' => 'LKR'], $overrides);
    }

    private function assertUnchanged(Payment $payment, Booking $booking): void
    {
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('accepted', $booking->fresh()->booking_status);
        $this->assertSame('pending', $booking->fresh()->payment_status);
    }
}
