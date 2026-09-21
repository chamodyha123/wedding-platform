<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServicePackage;
use App\Models\ServiceProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PaymentLifecycleSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped(
                'The configured SQLite test driver is not installed.'
            );
        }

        parent::setUp();

        foreach (['customer', 'service_provider', 'admin'] as $role) {
            Role::create([
                'name' => $role,
                'guard_name' => 'web',
            ]);
        }
    }

    public function test_customer_cannot_initiate_payment_for_another_customers_booking(): void
    {
        [$owner, $booking] = $this->createBookingFixture(
            'foreign-booking'
        );

        $otherCustomer = User::factory()->create([
            'email' => 'other-customer@example.test',
        ]);

        $otherCustomer->assignRole('customer');

        $token = $otherCustomer
            ->createToken('customer-token')
            ->plainTextToken;

        $this->withToken($token)
            ->postJson(
                '/api/customer/bookings/'.$booking->id.'/payments',
                [
                    'payment_method' => 'card',
                ]
            )
            ->assertNotFound();

        $this->assertSame(
            0,
            Payment::where('customer_id', $otherCustomer->id)->count()
        );

        $this->assertNotNull($owner);
    }

    public function test_only_accepted_booking_can_be_paid(): void
    {
        [$customer, $booking] = $this->createBookingFixture(
            'pending-booking',
            'pending'
        );

        $token = $customer
            ->createToken('customer-token')
            ->plainTextToken;

        $this->withToken($token)
            ->postJson(
                '/api/customer/bookings/'.$booking->id.'/payments',
                [
                    'payment_method' => 'card',
                ]
            )
            ->assertStatus(422)
            ->assertJson([
                'message' => 'Only accepted bookings can be paid.',
            ]);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_paid_booking_cannot_start_another_payment(): void
    {
        [$customer, $booking] = $this->createBookingFixture(
            'already-paid'
        );

        $booking->update([
            'payment_status' => 'paid',
        ]);

        $token = $customer
            ->createToken('customer-token')
            ->plainTextToken;

        $this->withToken($token)
            ->postJson(
                '/api/customer/bookings/'.$booking->id.'/payments',
                [
                    'payment_method' => 'card',
                ]
            )
            ->assertStatus(422)
            ->assertJson([
                'message' => 'This booking has already been paid.',
            ]);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_duplicate_active_payment_attempt_is_rejected(): void
    {
        [$customer, $booking] = $this->createBookingFixture(
            'duplicate'
        );

        $existingPayment = $this->createPayment(
            $booking,
            $customer,
            'duplicate-existing',
            'pending'
        );

        $token = $customer
            ->createToken('customer-token')
            ->plainTextToken;

        $this->withToken($token)
            ->postJson(
                '/api/customer/bookings/'.$booking->id.'/payments',
                [
                    'payment_method' => 'card',
                ]
            )
            ->assertStatus(409)
            ->assertJson([
                'message' => 'An active payment attempt already exists for this booking.',
            ]);

        $this->assertSame(
            1,
            Payment::where('booking_id', $booking->id)->count()
        );

        $this->assertDatabaseHas('payments', [
            'id' => $existingPayment->id,
            'status' => 'pending',
        ]);
    }

    public function test_payment_amount_is_taken_from_booking_not_request(): void
    {
        [$customer, $booking] = $this->createBookingFixture(
            'server-amount'
        );

        $token = $customer
            ->createToken('customer-token')
            ->plainTextToken;

        $response = $this->withToken($token)
            ->postJson(
                '/api/customer/bookings/'.$booking->id.'/payments',
                [
                    'payment_method' => 'card',
                    'amount' => 1,
                ]
            )
            ->assertCreated();

        $paymentId = $response->json('payment.id');

        $this->assertDatabaseHas('payments', [
            'id' => $paymentId,
            'booking_id' => $booking->id,
            'customer_id' => $customer->id,
            'amount' => 100,
        ]);
    }

    public function test_customer_cannot_confirm_another_customers_payment(): void
    {
        [$owner, $booking] = $this->createBookingFixture(
            'foreign-success'
        );

        $payment = $this->createPayment(
            $booking,
            $owner,
            'foreign-success',
            'pending'
        );

        $attacker = User::factory()->create([
            'email' => 'attacker@example.test',
        ]);

        $attacker->assignRole('customer');

        $token = $attacker
            ->createToken('customer-token')
            ->plainTextToken;

        $this->withToken($token)
            ->postJson(
                '/api/customer/bookings/'.
                $booking->id.
                '/payments/'.
                $payment->id.
                '/success'
            )
            ->assertNotFound();

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'booking_status' => 'accepted',
        ]);
    }

    public function test_payment_must_belong_to_specified_booking(): void
    {
        [$customer, $firstBooking] = $this->createBookingFixture(
            'first-booking'
        );

        [, $secondBooking] = $this->createBookingForCustomer(
            $customer,
            'second-booking'
        );

        $payment = $this->createPayment(
            $secondBooking,
            $customer,
            'second-payment',
            'pending'
        );

        $token = $customer
            ->createToken('customer-token')
            ->plainTextToken;

        $this->withToken($token)
            ->postJson(
                '/api/customer/bookings/'.
                $firstBooking->id.
                '/payments/'.
                $payment->id.
                '/success'
            )
            ->assertNotFound();

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'booking_id' => $secondBooking->id,
            'status' => 'pending',
        ]);
    }

    public function test_successful_payment_confirms_booking(): void
    {
        [$customer, $booking] = $this->createBookingFixture(
            'success'
        );

        $payment = $this->createPayment(
            $booking,
            $customer,
            'success',
            'pending'
        );

        $token = $customer
            ->createToken('customer-token')
            ->plainTextToken;

        $this->withToken($token)
            ->postJson(
                '/api/customer/bookings/'.
                $booking->id.
                '/payments/'.
                $payment->id.
                '/success'
            )
            ->assertOk()
            ->assertJsonPath(
                'payment.status',
                'paid'
            )
            ->assertJsonPath(
                'payment.booking.booking_status',
                'confirmed'
            )
            ->assertJsonPath(
                'payment.booking.payment_status',
                'paid'
            );

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'paid',
        ]);

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'booking_status' => 'confirmed',
            'payment_status' => 'paid',
        ]);

        $this->assertNotNull(
            $payment->fresh()->paid_at
        );

        $this->assertNotNull(
            $booking->fresh()->confirmed_at
        );
    }

    public function test_successful_payment_cannot_be_processed_twice(): void
    {
        [$customer, $booking] = $this->createBookingFixture(
            'double-success'
        );

        $payment = $this->createPayment(
            $booking,
            $customer,
            'double-success',
            'pending'
        );

        $token = $customer
            ->createToken('customer-token')
            ->plainTextToken;

        $url =
            '/api/customer/bookings/'.
            $booking->id.
            '/payments/'.
            $payment->id.
            '/success';

        $this->withToken($token)
            ->postJson($url)
            ->assertOk();

        $this->withToken($token)
            ->postJson($url)
            ->assertStatus(409)
            ->assertJson([
                'message' => 'This payment has already been processed successfully.',
            ]);

        $this->assertSame(
            1,
            Payment::where('booking_id', $booking->id)
                ->where('status', 'paid')
                ->count()
        );
    }

    public function test_failed_payment_allows_new_payment_attempt(): void
    {
        [$customer, $booking] = $this->createBookingFixture(
            'failed-retry'
        );

        $payment = $this->createPayment(
            $booking,
            $customer,
            'failed-retry',
            'pending'
        );

        $token = $customer
            ->createToken('customer-token')
            ->plainTextToken;

        $this->withToken($token)
            ->postJson(
                '/api/customer/bookings/'.
                $booking->id.
                '/payments/'.
                $payment->id.
                '/fail',
                [
                    'failure_reason' => 'Test failure',
                ]
            )
            ->assertOk();

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'failed',
        ]);

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'booking_status' => 'accepted',
            'payment_status' => 'unpaid',
        ]);

        $this->withToken($token)
            ->postJson(
                '/api/customer/bookings/'.$booking->id.'/payments',
                [
                    'payment_method' => 'card',
                ]
            )
            ->assertCreated();

        $this->assertSame(
            2,
            Payment::where('booking_id', $booking->id)->count()
        );
    }

    public function test_cancelled_payment_allows_new_payment_attempt(): void
    {
        [$customer, $booking] = $this->createBookingFixture(
            'cancelled-retry'
        );

        $payment = $this->createPayment(
            $booking,
            $customer,
            'cancelled-retry',
            'pending'
        );

        $token = $customer
            ->createToken('customer-token')
            ->plainTextToken;

        $this->withToken($token)
            ->postJson(
                '/api/customer/bookings/'.
                $booking->id.
                '/payments/'.
                $payment->id.
                '/cancel'
            )
            ->assertOk();

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'cancelled',
        ]);

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'booking_status' => 'accepted',
            'payment_status' => 'unpaid',
        ]);

        $this->withToken($token)
            ->postJson(
                '/api/customer/bookings/'.$booking->id.'/payments',
                [
                    'payment_method' => 'bank_transfer',
                ]
            )
            ->assertCreated();

        $this->assertSame(
            2,
            Payment::where('booking_id', $booking->id)->count()
        );
    }

    public function test_paid_payment_cannot_be_marked_as_failed(): void
    {
        [$customer, $booking] = $this->createBookingFixture(
            'paid-fail'
        );

        $payment = $this->createPayment(
            $booking,
            $customer,
            'paid-fail',
            'paid'
        );

        $payment->forceFill([
            'paid_at' => now(),
        ])->save();

        $booking->update([
            'booking_status' => 'confirmed',
            'payment_status' => 'paid',
            'confirmed_at' => now(),
        ]);

        $token = $customer
            ->createToken('customer-token')
            ->plainTextToken;

        $this->withToken($token)
            ->postJson(
                '/api/customer/bookings/'.
                $booking->id.
                '/payments/'.
                $payment->id.
                '/fail',
                [
                    'failure_reason' => 'Should not work',
                ]
            )
            ->assertStatus(409);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'paid',
        ]);
    }

    public function test_paid_payment_cannot_be_cancelled(): void
    {
        [$customer, $booking] = $this->createBookingFixture(
            'paid-cancel'
        );

        $payment = $this->createPayment(
            $booking,
            $customer,
            'paid-cancel',
            'paid'
        );

        $payment->forceFill([
            'paid_at' => now(),
        ])->save();

        $booking->update([
            'booking_status' => 'confirmed',
            'payment_status' => 'paid',
            'confirmed_at' => now(),
        ]);

        $token = $customer
            ->createToken('customer-token')
            ->plainTextToken;

        $this->withToken($token)
            ->postJson(
                '/api/customer/bookings/'.
                $booking->id.
                '/payments/'.
                $payment->id.
                '/cancel'
            )
            ->assertStatus(409);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'paid',
        ]);
    }

    public function test_mock_payment_endpoints_are_disabled_in_production(): void
    {
        [$customer, $booking] = $this->createBookingFixture(
            'production'
        );

        $payment = $this->createPayment(
            $booking,
            $customer,
            'production',
            'pending'
        );

        $token = $customer
            ->createToken('customer-token')
            ->plainTextToken;

        /*
         * Force Laravel's application environment resolver
         * to production for this test.
         *
         * Changing config('app.env') alone is not enough
         * after Laravel has already resolved the environment.
         */
        $this->app->detectEnvironment(
            fn (): string => 'production'
        );

        /*
         * Make sure this test is genuinely executing with
         * Laravel seeing the production environment.
         */
        $this->assertTrue(
            app()->environment('production')
        );

        $this->assertFalse(
            app()->environment(['local', 'testing'])
        );

        $baseUrl =
            '/api/customer/bookings/'.
            $booking->id.
            '/payments/'.
            $payment->id;

        $this->withToken($token)
            ->postJson($baseUrl.'/success')
            ->assertNotFound();

        $this->withToken($token)
            ->postJson($baseUrl.'/fail')
            ->assertNotFound();

        $this->withToken($token)
            ->postJson($baseUrl.'/cancel')
            ->assertNotFound();

        /*
         * None of the disabled mock endpoints may mutate
         * the payment.
         */
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'pending',
        ]);

        /*
         * The booking must remain accepted and unpaid.
         */
        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'booking_status' => 'accepted',
            'payment_status' => 'unpaid',
        ]);
    }

    /**
     * @return array{0: User, 1: Booking}
     */
    private function createBookingFixture(
        string $suffix,
        string $bookingStatus = 'accepted'
    ): array {
        $customer = User::factory()->create([
            'email' => $suffix.'-customer@example.test',
        ]);

        $customer->assignRole('customer');

        return $this->createBookingForCustomer(
            $customer,
            $suffix,
            $bookingStatus
        );
    }

    /**
     * @return array{0: User, 1: Booking}
     */
    private function createBookingForCustomer(
        User $customer,
        string $suffix,
        string $bookingStatus = 'accepted'
    ): array {
        $providerUser = User::factory()->create([
            'email' => $suffix.'-provider@example.test',
        ]);

        $providerUser->assignRole('service_provider');

        $provider = ServiceProvider::create([
            'user_id' => $providerUser->id,
            'business_name' => 'Provider '.$suffix,
            'business_slug' => 'provider-'.$suffix,
            'verification_status' => 'verified',
            'is_active' => true,
        ]);

        $category = ServiceCategory::create([
            'name' => 'Category '.$suffix,
            'slug' => 'category-'.$suffix,
        ]);

        $service = Service::create([
            'service_provider_id' => $provider->id,
            'service_category_id' => $category->id,
            'name' => 'Service '.$suffix,
            'slug' => 'service-'.$suffix,
            'status' => 'published',
        ]);

        $package = ServicePackage::create([
            'service_id' => $service->id,
            'name' => 'Package '.$suffix,
            'slug' => 'package-'.$suffix,
            'price' => 100,
            'status' => 'published',
        ]);

        $booking = Booking::create([
            'booking_reference' => 'BK-'.$suffix,
            'customer_id' => $customer->id,
            'service_provider_id' => $provider->id,
            'service_id' => $service->id,
            'service_package_id' => $package->id,
            'event_date' => now()
                ->addDays(3)
                ->toDateString(),
            'start_time' => '10:00',
            'end_time' => '11:00',
            'event_location' => 'Test Location',
            'total_amount' => 100,
            'booking_status' => $bookingStatus,
            'payment_status' => 'unpaid',
        ]);

        return [
            $customer,
            $booking,
        ];
    }

    private function createPayment(
        Booking $booking,
        User $customer,
        string $suffix,
        string $status
    ): Payment {
        return Payment::create([
            'booking_id' => $booking->id,
            'customer_id' => $customer->id,
            'payment_reference' => 'PAY-'.$suffix,
            'amount' => $booking->total_amount,
            'currency' => 'LKR',
            'payment_method' => 'card',
            'status' => $status,
        ]);
    }
}