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

class AdminPaymentVisibilityTest extends TestCase
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

    public function test_admin_can_list_all_payments_in_newest_order(): void
    {
        $admin = $this->createAdmin();

        $olderPayment = $this->createPaymentFixture('older');

        $olderPayment->forceFill([
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ])->save();

        $newerPayment = $this->createPaymentFixture('newer');

        $newerPayment->forceFill([
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        $token = $admin->createToken(
            'admin-token'
        )->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/admin/payments')
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Admin payments loaded successfully.'
            )
            ->assertJsonStructure([
                'message',
                'payments' => [
                    '*' => [
                        'id',
                        'payment_reference',
                        'booking_id',
                        'customer_id',
                        'amount',
                        'currency',
                        'payment_method',
                        'status',
                        'gateway',
                        'failure_reason',
                        'paid_at',
                        'failed_at',
                        'cancelled_at',
                        'refunded_at',
                        'created_at',
                        'updated_at',
                        'booking' => [
                            'id',
                            'booking_reference',
                            'customer_id',
                            'service_provider_id',
                            'service_id',
                            'service_package_id',
                            'event_date',
                            'start_time',
                            'end_time',
                            'event_location',
                            'total_amount',
                            'booking_status',
                            'payment_status',
                            'confirmed_at',
                            'completed_at',
                            'cancelled_at',
                            'customer' => [
                                'id',
                                'name',
                                'email',
                            ],
                            'provider' => [
                                'id',
                                'business_name',
                            ],
                            'service' => [
                                'id',
                                'name',
                                'slug',
                            ],
                            'package' => [
                                'id',
                                'name',
                                'slug',
                                'price',
                                'duration_minutes',
                            ],
                        ],
                    ],
                ],
            ]);

        $payments = $response->json('payments');

        $this->assertCount(2, $payments);

        $this->assertSame(
            $newerPayment->id,
            $payments[0]['id']
        );

        $this->assertSame(
            $olderPayment->id,
            $payments[1]['id']
        );
    }

    public function test_admin_can_view_payment_details(): void
    {
        $admin = $this->createAdmin();
        $payment = $this->createPaymentFixture('details');

        $token = $admin->createToken(
            'admin-token'
        )->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/admin/payments/'.$payment->id)
            ->assertOk()
            ->assertJsonPath(
                'payment.id',
                $payment->id
            )
            ->assertJsonPath(
                'payment.payment_reference',
                'PAY-details'
            )
            ->assertJsonPath(
                'payment.booking.booking_reference',
                'BK-details'
            )
            ->assertJsonPath(
                'payment.booking.customer.email',
                'details-customer@example.test'
            )
            ->assertJsonPath(
                'payment.booking.provider.business_name',
                'Provider details'
            )
            ->assertJsonPath(
                'payment.booking.service.name',
                'Service details'
            )
            ->assertJsonPath(
                'payment.booking.package.name',
                'Package details'
            );
    }

    public function test_admin_payment_response_hides_sensitive_fields(): void
    {
        $admin = $this->createAdmin();
        $payment = $this->createPaymentFixture('sensitive');

        $token = $admin->createToken(
            'admin-token'
        )->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/admin/payments/'.$payment->id)
            ->assertOk();

        $paymentData = $response->json('payment');

        $this->assertArrayNotHasKey(
            'metadata',
            $paymentData
        );

        $this->assertArrayNotHasKey(
            'gateway_transaction_id',
            $paymentData
        );

        $this->assertArrayNotHasKey(
            'password',
            $paymentData['booking']['customer']
        );

        $this->assertArrayNotHasKey(
            'remember_token',
            $paymentData['booking']['customer']
        );
    }

    public function test_admin_can_see_payments_from_different_providers(): void
    {
        $admin = $this->createAdmin();

        $firstPayment = $this->createPaymentFixture(
            'provider-one'
        );

        $secondPayment = $this->createPaymentFixture(
            'provider-two'
        );

        $token = $admin->createToken(
            'admin-token'
        )->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/admin/payments')
            ->assertOk();

        $payments = $response->json('payments');

        $ids = array_column(
            $payments,
            'id'
        );

        $this->assertContains(
            $firstPayment->id,
            $ids
        );

        $this->assertContains(
            $secondPayment->id,
            $ids
        );
    }

    public function test_nonexistent_payment_returns_not_found(): void
    {
        $admin = $this->createAdmin();

        $token = $admin->createToken(
            'admin-token'
        )->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/admin/payments/999999')
            ->assertNotFound()
            ->assertJson([
                'message' => 'Payment not found.',
            ]);
    }

    public function test_customer_cannot_access_admin_payment_routes(): void
    {
        $payment = $this->createPaymentFixture('customer-block');

        $customer = User::factory()->create([
            'email' => 'blocked-customer@example.test',
        ]);

        $customer->assignRole('customer');

        $token = $customer->createToken(
            'customer-token'
        )->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/admin/payments')
            ->assertForbidden();

        $this->withToken($token)
            ->getJson('/api/admin/payments/'.$payment->id)
            ->assertForbidden();
    }

    public function test_provider_cannot_access_admin_payment_routes(): void
    {
        $payment = $this->createPaymentFixture('provider-block');

        $providerUser = User::factory()->create([
            'email' => 'blocked-provider@example.test',
        ]);

        $providerUser->assignRole('service_provider');

        $token = $providerUser->createToken(
            'provider-token'
        )->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/admin/payments')
            ->assertForbidden();

        $this->withToken($token)
            ->getJson('/api/admin/payments/'.$payment->id)
            ->assertForbidden();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/admin/payments')
            ->assertUnauthorized();

        $this->getJson('/api/admin/payments/1')
            ->assertUnauthorized();
    }

    private function createAdmin(): User
    {
        $admin = User::factory()->create([
            'email' => 'admin@example.test',
        ]);

        $admin->assignRole('admin');

        return $admin;
    }

    private function createPaymentFixture(
        string $suffix
    ): Payment {
        $providerUser = User::factory()->create([
            'email' => $suffix.'-provider@example.test',
        ]);

        $providerUser->assignRole(
            'service_provider'
        );

        $provider = ServiceProvider::create([
            'user_id' => $providerUser->id,
            'business_name' => 'Provider '.$suffix,
            'business_slug' => 'provider-'.$suffix,
            'verification_status' => 'pending',
            'is_active' => true,
        ]);

        $customer = User::factory()->create([
            'email' => $suffix.'-customer@example.test',
        ]);

        $customer->assignRole('customer');

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
            'booking_status' => 'accepted',
            'payment_status' => 'pending',
        ]);

        return Payment::create([
            'booking_id' => $booking->id,
            'customer_id' => $customer->id,
            'payment_reference' => 'PAY-'.$suffix,
            'amount' => 100,
            'currency' => 'LKR',
            'payment_method' => 'card',
            'status' => 'pending',

            // Intentionally populated so the API test
            // proves these internal fields are hidden.
            'metadata' => [
                'internal' => true,
                'secret_test_value' => 'must-not-be-exposed',
            ],

            'gateway_transaction_id' =>
                'gateway-'.$suffix,
        ]);
    }
}