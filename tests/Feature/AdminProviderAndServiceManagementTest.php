<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Review;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminProviderAndServiceManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('The configured SQLite test driver is not installed.');
        }
        parent::setUp();
        foreach (['customer', 'service_provider', 'admin'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_admin_lists_and_views_providers_with_filters_and_aggregates(): void
    {
        $admin = $this->user('admin', 'admin@example.test');
        [$provider, $service] = $this->fixture('alpha', 'verified', true, 'published');
        [$otherProvider] = $this->fixture('beta', 'pending', false, 'draft');
        $this->review($provider, $service, 'alpha-review', 5);
        $data = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/providers?search=alpha&verification_status=verified&is_active=1&per_page=1')
            ->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('providers.0.id', $provider->id)
            ->assertJsonPath('providers.0.services_count', 1)->assertJsonPath('providers.0.bookings_count', 1)
            ->assertJsonPath('providers.0.reviews_count', 1)->assertJsonPath('providers.0.average_rating', 5)->json('providers.0');
        $this->assertArrayNotHasKey('password', $data['user']);
        $this->assertNotSame($otherProvider->id, $data['id']);
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/providers/'.$provider->id)->assertOk()->assertJsonPath('provider.id', $provider->id);
    }

    public function test_provider_filters_validate_and_existing_verification_action_works(): void
    {
        $admin = $this->user('admin', 'admin@example.test');
        [$provider] = $this->fixture('verify', 'pending', true, 'draft');
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/providers?per_page=101')->assertUnprocessable()->assertJsonValidationErrors(['per_page']);
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/providers?verification_status=unknown')->assertUnprocessable();
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/providers/999999')->assertNotFound();
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/providers/'.$provider->id.'/approve', ['notes' => 'Approved'])->assertOk();
        $this->assertDatabaseHas('service_providers', ['id' => $provider->id, 'verification_status' => 'verified']);
    }

    public function test_admin_sees_non_public_services_and_filters_with_review_aggregates(): void
    {
        $admin = $this->user('admin', 'admin@example.test');
        [$provider, $service, $category] = $this->fixture('hidden', 'pending', false, 'draft');
        $this->review($provider, $service, 'hidden-one', 4);
        $this->review($provider, $service, 'hidden-two', 5);
        [, $otherService] = $this->fixture('visible', 'verified', true, 'published');
        $data = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/services?search=hidden&provider_id='.$provider->id.'&category_id='.$category->id.'&status=draft')
            ->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('services.0.id', $service->id)
            ->assertJsonPath('services.0.reviews_count', 2)->assertJsonPath('services.0.average_rating', 4.5)->json('services.0');
        $this->assertSame($provider->id, $data['provider']['id']);
        $this->assertNotSame($otherService->id, $data['id']);
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/services/'.$service->id)->assertOk()->assertJsonPath('service.status', 'draft');
    }

    public function test_service_filters_validate_and_missing_service_returns_not_found(): void
    {
        $admin = $this->user('admin', 'admin@example.test');
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/services?status=unknown')->assertUnprocessable();
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/services?per_page=101')->assertUnprocessable();
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/services/999999')->assertNotFound();
    }

    public function test_admin_lists_and_views_categories_with_counts_and_filter(): void
    {
        $admin = $this->user('admin', 'admin@example.test');
        [, , $category] = $this->fixture('category', 'verified', true, 'published');
        $inactive = ServiceCategory::create(['name' => 'Inactive', 'slug' => 'inactive', 'is_active' => false]);
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/categories?is_active=1')->assertOk()->assertJsonPath('categories.0.id', $category->id)->assertJsonPath('categories.0.services_count', 1);
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/categories/'.$inactive->id)->assertOk()->assertJsonPath('category.is_active', false);
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/categories/999999')->assertNotFound();
    }

    public function test_admin_routes_require_admin_role(): void
    {
        $customer = $this->user('customer', 'customer@example.test');
        $provider = $this->user('service_provider', 'provider@example.test');
        $urls = ['/api/admin/providers', '/api/admin/providers/1', '/api/admin/services', '/api/admin/services/1', '/api/admin/categories', '/api/admin/categories/1'];
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

    private function user(string $role, string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->assignRole($role);

        return $user;
    }

    private function fixture(string $suffix, string $verificationStatus, bool $isActive, string $serviceStatus): array
    {
        $providerUser = $this->user('service_provider', $suffix.'-provider@example.test');
        $provider = ServiceProvider::create(['user_id' => $providerUser->id, 'business_name' => 'Provider '.$suffix, 'business_slug' => 'provider-'.$suffix, 'verification_status' => $verificationStatus, 'is_active' => $isActive]);
        $category = ServiceCategory::create(['name' => 'Category '.$suffix, 'slug' => 'category-'.$suffix, 'is_active' => true]);
        $service = Service::create(['service_provider_id' => $provider->id, 'service_category_id' => $category->id, 'name' => 'Service '.$suffix, 'slug' => 'service-'.$suffix, 'status' => $serviceStatus]);

        return [$provider, $service, $category];
    }

    private function review(ServiceProvider $provider, Service $service, string $suffix, int $rating): void
    {
        $customer = $this->user('customer', $suffix.'@example.test');
        $booking = Booking::create(['booking_reference' => 'BK-'.$suffix, 'customer_id' => $customer->id, 'service_provider_id' => $provider->id, 'service_id' => $service->id, 'event_date' => now()->addDay()->toDateString(), 'total_amount' => 100, 'booking_status' => 'completed', 'payment_status' => 'unpaid']);
        Review::create(['booking_id' => $booking->id, 'customer_id' => $customer->id, 'service_provider_id' => $provider->id, 'service_id' => $service->id, 'rating' => $rating]);
    }
}
