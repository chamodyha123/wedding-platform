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

class ProviderPaymentVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('The configured SQLite test driver is not installed.');
        }

        parent::setUp();

        foreach (['customer', 'service_provider', 'admin'] as $role) {
            Role::create([
                'name' => $role,
                'guard_name' => 'web',
            ]);
        }
    }

    public function test_provider_can_list_only_payments_for_owned_bookings_in_newest_order(): void
    {
        [$providerUser, $ownedPayment] = $this->createPaymentFixture('owned');
        [, $foreignPayment] = $this->createPaymentFixture('foreign');

        $ownedPayment->forceFill([
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ])->save();
        $foreignPayment->forceFill([
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        $newerOwnedPayment = $this->createPaymentForProvider(
            ServiceProvider::where('user_id', $providerUser->id)->firstOrFail(),
            'owned-newer'
        );

        $token = $providerUser->createToken('provider-token')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/provider/payments')
            ->assertOk()
            ->assertJsonStructure([
                'payments' => [
                    '*' => [
                        'id',
                        'payment_reference',
                        'booking_id',
                        'amount',
                        'currency',
                        'status',
                        'booking' => [
                            'booking_reference',
                            'event_date',
                            'booking_status',
                            'payment_status',
                            'customer',
                            'service',
                            'package',
                        ],
                    ],
                ],
            ]);

        $payments = $response->json('payments');

        $this->assertCount(2, $payments);
        $this->assertSame($newerOwnedPayment->id, $payments[0]['id']);
        $this->assertSame($ownedPayment->id, $payments[1]['id']);
        $this->assertNotContains($foreignPayment->id, array_column($payments, 'id'));
        $this->assertArrayNotHasKey('metadata', $payments[0]);
        $this->assertArrayNotHasKey('gateway_transaction_id', $payments[0]);
        $this->assertArrayNotHasKey('password', $payments[0]['booking']['customer']);
        $this->assertArrayNotHasKey('remember_token', $payments[0]['booking']['customer']);
    }

    public function test_provider_can_view_owned_payment_details(): void
    {
        [$providerUser, $payment] = $this->createPaymentFixture('details');
        $token = $providerUser->createToken('provider-token')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/provider/payments/'.$payment->id)
            ->assertOk()
            ->assertJsonPath('payment.id', $payment->id)
            ->assertJsonPath('payment.booking.booking_reference', 'BK-details');
    }

    public function test_provider_cannot_view_another_providers_payment(): void
    {
        [$providerUser] = $this->createPaymentFixture('first');
        [, $foreignPayment] = $this->createPaymentFixture('second');
        $token = $providerUser->createToken('provider-token')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/provider/payments/'.$foreignPayment->id)
            ->assertNotFound();
    }

    public function test_nonexistent_payment_returns_not_found(): void
    {
        [$providerUser] = $this->createPaymentFixture('missing');
        $token = $providerUser->createToken('provider-token')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/provider/payments/999999')
            ->assertNotFound();
    }

    public function test_customer_cannot_access_provider_payment_routes(): void
    {
        [$providerUser, $payment] = $this->createPaymentFixture('customer');
        $customer = User::factory()->create();
        $customer->assignRole('customer');
        $token = $customer->createToken('customer-token')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/provider/payments')
            ->assertForbidden();

        $this->withToken($token)
            ->getJson('/api/provider/payments/'.$payment->id)
            ->assertForbidden();

        $this->assertNotNull($providerUser);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/provider/payments')->assertUnauthorized();
        $this->getJson('/api/provider/payments/1')->assertUnauthorized();
    }

    /**
     * @return array{0: User, 1: Payment}
     */
    private function createPaymentFixture(string $suffix): array
    {
        $providerUser = User::factory()->create([
            'email' => $suffix.'-provider@example.test',
        ]);
        $providerUser->assignRole('service_provider');

        $provider = ServiceProvider::create([
            'user_id' => $providerUser->id,
            'business_name' => 'Provider '.$suffix,
            'business_slug' => 'provider-'.$suffix,
            'verification_status' => 'pending',
            'is_active' => true,
        ]);

        return [
            $providerUser,
            $this->createPaymentForProvider($provider, $suffix),
        ];
    }

    private function createPaymentForProvider(
        ServiceProvider $provider,
        string $suffix
    ): Payment {
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
            'event_date' => now()->addDays(3)->toDateString(),
            'start_time' => '10:00',
            'end_time' => '11:00',
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
            'metadata' => ['internal' => true],
            'gateway_transaction_id' => 'gateway-'.$suffix,
        ]);
    }
}
