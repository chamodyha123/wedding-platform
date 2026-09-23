<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Review;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminDashboardAndCustomerManagementTest extends TestCase
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

    public function test_admin_dashboard_returns_correct_aggregate_summary(): void
    {
        $admin = $this->createUserWithRole('admin', 'admin@example.test');
        $customer = $this->createUserWithRole('customer', 'customer@example.test');
        $this->createUserWithRole('customer', 'customer-two@example.test');
        $provider = $this->createProvider('verified', true, 'first');
        $this->createProvider('pending', false, 'second');
        $fixture = $this->createBookingFixture($customer, $provider, 'first', 'completed');

        Service::create([
            'service_provider_id' => $provider->id,
            'service_category_id' => $fixture['category']->id,
            'name' => 'Draft service',
            'slug' => 'draft-service',
            'status' => 'draft',
        ]);

        Booking::create([
            ...$fixture['booking']->only([
                'customer_id',
                'service_provider_id',
                'service_id',
                'event_date',
                'start_time',
                'end_time',
                'event_location',
                'total_amount',
                'payment_status',
            ]),
            'booking_reference' => 'BK-pending',
            'booking_status' => 'pending',
        ]);

        Payment::create([
            'booking_id' => $fixture['booking']->id,
            'customer_id' => $customer->id,
            'payment_reference' => 'PAY-first',
            'amount' => 150,
            'status' => 'paid',
        ]);
        Payment::create([
            'booking_id' => $fixture['booking']->id,
            'customer_id' => $customer->id,
            'payment_reference' => 'PAY-duplicate',
            'amount' => 150,
            'status' => 'paid',
        ]);
        Payment::create([
            'booking_id' => $fixture['booking']->id,
            'customer_id' => $customer->id,
            'payment_reference' => 'PAY-failed',
            'amount' => 200,
            'status' => 'failed',
        ]);

        Review::create([
            'booking_id' => $fixture['booking']->id,
            'customer_id' => $customer->id,
            'service_provider_id' => $provider->id,
            'service_id' => $fixture['service']->id,
            'rating' => 4,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/dashboard')
            ->assertOk();

        $response->assertJsonPath('total_customers', 2)
            ->assertJsonPath('total_service_providers', 2)
            ->assertJsonPath('verified_provider_count', 1)
            ->assertJsonPath('unverified_provider_count', 1)
            ->assertJsonPath('active_provider_count', 1)
            ->assertJsonPath('total_services', 2)
            ->assertJsonPath('published_service_count', 1)
            ->assertJsonPath('total_bookings', 2)
            ->assertJsonPath('booking_status_counts.completed', 1)
            ->assertJsonPath('booking_status_counts.pending', 1)
            ->assertJsonPath('completed_booking_count', 1)
            ->assertJsonPath('payment_revenue_summary', 150)
            ->assertJsonPath('total_reviews', 1)
            ->assertJsonPath('average_review_rating', 4);
    }

    public function test_admin_customer_list_only_contains_customers_and_supports_search_and_pagination(): void
    {
        $admin = $this->createUserWithRole('admin', 'admin@example.test');
        $firstCustomer = $this->createUserWithRole('customer', 'alice@example.test', 'Alice Bride');
        $secondCustomer = $this->createUserWithRole('customer', 'beth@example.test', 'Beth Groom');
        $this->createUserWithRole('service_provider', 'provider@example.test', 'Alice Provider');

        $firstCustomer->forceFill(['created_at' => now()->subMinute()])->save();
        $secondCustomer->forceFill(['created_at' => now()])->save();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/customers?search=alice&per_page=1')
            ->assertOk()
            ->assertJsonPath('pagination.current_page', 1)
            ->assertJsonPath('pagination.per_page', 1)
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('customers.0.id', $firstCustomer->id);

        $this->assertSame($firstCustomer->id, $response->json('customers.0.id'));
        $this->assertNotSame($secondCustomer->id, $response->json('customers.0.id'));
    }

    public function test_admin_customer_detail_returns_safe_customer_data_and_counts(): void
    {
        $admin = $this->createUserWithRole('admin', 'admin@example.test');
        $customer = $this->createUserWithRole('customer', 'customer@example.test');
        $provider = $this->createProvider('verified', true, 'detail');
        $fixture = $this->createBookingFixture($customer, $provider, 'detail', 'completed');

        Review::create([
            'booking_id' => $fixture['booking']->id,
            'customer_id' => $customer->id,
            'service_provider_id' => $provider->id,
            'service_id' => $fixture['service']->id,
            'rating' => 5,
        ]);

        $customer->forceFill([
            'remember_token' => 'private-token',
        ])->save();
        $customer->createToken('private-api-token');

        $customerData = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/customers/'.$customer->id)
            ->assertOk()
            ->assertJsonPath('customer.id', $customer->id)
            ->assertJsonPath('customer.bookings_count', 1)
            ->assertJsonPath('customer.reviews_count', 1)
            ->json('customer');

        $this->assertArrayNotHasKey('password', $customerData);
        $this->assertArrayNotHasKey('remember_token', $customerData);
        $this->assertArrayNotHasKey('tokens', $customerData);
    }

    public function test_admin_customer_routes_validate_pagination_and_return_not_found_for_non_customers(): void
    {
        $admin = $this->createUserWithRole('admin', 'admin@example.test');
        $provider = $this->createUserWithRole('service_provider', 'provider@example.test');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/customers?per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/customers/'.$provider->id)
            ->assertNotFound()
            ->assertJsonPath('message', 'Customer not found.');
    }

    public function test_admin_dashboard_and_customer_routes_require_an_administrator(): void
    {
        $customer = $this->createUserWithRole('customer', 'customer@example.test');
        $provider = $this->createUserWithRole('service_provider', 'provider@example.test');

        $urls = [
            '/api/admin/dashboard',
            '/api/admin/customers',
            '/api/admin/customers/1',
        ];

        foreach ($urls as $url) {
            $this->getJson($url)->assertUnauthorized();
        }

        foreach ($urls as $url) {
            $this->actingAs($customer, 'sanctum')->getJson($url)->assertForbidden();
        }

        foreach ($urls as $url) {
            $this->actingAs($provider, 'sanctum')->getJson($url)->assertForbidden();
        }
    }

    private function createUserWithRole(string $role, string $email, ?string $name = null): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'name' => $name ?? ucfirst($role),
        ]);

        $user->assignRole($role);

        return $user;
    }

    private function createProvider(string $status, bool $isActive, string $suffix): ServiceProvider
    {
        $user = $this->createUserWithRole(
            'service_provider',
            $suffix.'-provider@example.test'
        );

        return ServiceProvider::create([
            'user_id' => $user->id,
            'business_name' => 'Provider '.$suffix,
            'business_slug' => 'provider-'.$suffix,
            'verification_status' => $status,
            'is_active' => $isActive,
        ]);
    }

    /**
     * @return array{booking: Booking, category: ServiceCategory, service: Service}
     */
    private function createBookingFixture(
        User $customer,
        ServiceProvider $provider,
        string $suffix,
        string $bookingStatus
    ): array {
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
        $booking = Booking::create([
            'booking_reference' => 'BK-'.$suffix,
            'customer_id' => $customer->id,
            'service_provider_id' => $provider->id,
            'service_id' => $service->id,
            'event_date' => now()->addDay()->toDateString(),
            'start_time' => '10:00',
            'end_time' => '11:00',
            'event_location' => 'Test location',
            'total_amount' => 150,
            'booking_status' => $bookingStatus,
            'payment_status' => 'unpaid',
        ]);

        return compact('booking', 'category', 'service');
    }
}
