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
use Illuminate\Support\Facades\Config;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuthAndPaymentSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('The configured SQLite test driver is not installed.');
        }

        parent::setUp();

        Role::create([
            'name' => 'customer',
            'guard_name' => 'web',
        ]);

        Role::create([
            'name' => 'service_provider',
            'guard_name' => 'web',
        ]);

        Role::create([
            'name' => 'admin',
            'guard_name' => 'web',
        ]);
    }

    public function test_invalid_login_is_rate_limited(): void
    {
        $user = User::factory()->create([
            'email' => 'customer@example.test',
            'password' => 'password',
        ]);

        $user->assignRole('customer');

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])->assertStatus(401);
        }

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    public function test_protected_route_requires_authentication(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_mock_payment_success_is_disabled_outside_local_and_testing(): void
    {
        $customer = User::factory()->create();
        $customer->assignRole('customer');
        $token = $customer->createToken('test-token')->plainTextToken;

        Config::set('app.env', 'production');

        $this->withToken($token)
            ->postJson('/api/customer/bookings/1/payments/1/success')
            ->assertNotFound();
    }

    public function test_customer_cannot_access_another_customers_payment(): void
    {
        $customer = User::factory()->create();
        $customer->assignRole('customer');
        $otherCustomer = User::factory()->create();
        $otherCustomer->assignRole('customer');

        $providerUser = User::factory()->create();
        $providerUser->assignRole('service_provider');
        $provider = ServiceProvider::create([
            'user_id' => $providerUser->id,
            'business_name' => 'Test Provider',
            'business_slug' => 'test-provider',
            'verification_status' => 'verified',
            'is_active' => true,
        ]);
        $service = Service::create([
            'service_provider_id' => $provider->id,
            'service_category_id' => $this->createCategoryId(),
            'name' => 'Photography',
            'slug' => 'photography',
            'status' => 'published',
        ]);
        $package = ServicePackage::create([
            'service_id' => $service->id,
            'name' => 'Full Day',
            'slug' => 'full-day',
            'price' => 100,
            'status' => 'published',
        ]);
        $booking = Booking::create([
            'booking_reference' => 'BK-OTHER-001',
            'customer_id' => $otherCustomer->id,
            'service_provider_id' => $provider->id,
            'service_id' => $service->id,
            'service_package_id' => $package->id,
            'event_date' => now()->addDays(2)->toDateString(),
            'start_time' => '10:00',
            'end_time' => '11:00',
            'total_amount' => 100,
            'booking_status' => 'accepted',
            'payment_status' => 'unpaid',
        ]);
        $payment = Payment::create([
            'booking_id' => $booking->id,
            'customer_id' => $otherCustomer->id,
            'payment_reference' => 'PAY-OTHER-001',
            'amount' => 100,
            'currency' => 'LKR',
            'status' => 'pending',
        ]);

        $token = $customer->createToken('test-token')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/customer/payments/'.$payment->id)
            ->assertNotFound();
    }

    private function createCategoryId(): int
    {
        return ServiceCategory::create([
            'name' => 'Photography',
            'slug' => 'photography',
        ])->id;
    }
}
