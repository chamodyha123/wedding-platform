<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminReviewAndReportingTest extends TestCase
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

    public function test_admin_review_list_validates_filters_and_report_has_zero_data_aggregates(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/reviews')->assertOk()->assertJsonPath('pagination.total', 0);
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/reviews?rating=0')->assertUnprocessable();
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/reviews?rating=6')->assertUnprocessable();
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/reviews?per_page=101')->assertUnprocessable();
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/reviews/999999')->assertNotFound();
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/reports/overview')->assertOk()->assertJsonPath('bookings_created_at.total', 0)->assertJsonPath('revenue_paid_at.successful_payment_count', 0)->assertJsonPath('revenue_paid_at.realized_revenue', 0)->assertJsonPath('reviews_created_at.total', 0)->assertJsonPath('reviews_created_at.average_rating', null);
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/reports/overview?from=bad')->assertUnprocessable();
    }

    public function test_review_and_report_routes_require_admin_role(): void
    {
        $customer = $this->user('customer');
        $provider = $this->user('service_provider');
        foreach (['/api/admin/reviews', '/api/admin/reviews/1', '/api/admin/reports/overview'] as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
        foreach (['/api/admin/reviews', '/api/admin/reports/overview'] as $url) {
            $this->actingAs($customer, 'sanctum')->getJson($url)->assertForbidden();
            $this->actingAs($provider, 'sanctum')->getJson($url)->assertForbidden();
        }
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }
}
