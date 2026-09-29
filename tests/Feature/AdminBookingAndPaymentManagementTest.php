<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminBookingAndPaymentManagementTest extends TestCase
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

    public function test_admin_booking_and_payment_lists_validate_filters_and_return_empty_paginated_results(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/bookings?booking_status=unknown')->assertUnprocessable();
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/bookings?event_date_from=tomorrow&event_date_to=yesterday')->assertUnprocessable();
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/bookings?per_page=101')->assertUnprocessable();
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/bookings')->assertOk()->assertJsonPath('pagination.total', 0);
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/payments?status=unknown')->assertUnprocessable();
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/payments?per_page=101')->assertUnprocessable();
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/payments')->assertOk()->assertJsonPath('pagination.total', 0);
    }

    public function test_booking_and_payment_detail_missing_and_role_boundaries_are_enforced(): void
    {
        $customer = $this->user('customer');
        $provider = $this->user('service_provider');
        $admin = $this->user('admin');
        $urls = ['/api/admin/bookings', '/api/admin/bookings/1', '/api/admin/payments', '/api/admin/payments/1'];
        foreach ($urls as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
        foreach ($urls as $url) {
            $this->actingAs($customer, 'sanctum')->getJson($url)->assertForbidden();
        }
        foreach ($urls as $url) {
            $this->actingAs($provider, 'sanctum')->getJson($url)->assertForbidden();
        }
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/bookings/999999')->assertNotFound();
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/payments/999999')->assertNotFound();
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }
}
