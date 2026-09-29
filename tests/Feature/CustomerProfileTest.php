<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\CustomerProfile;
use App\Models\User;
use Tests\TestCase;
use Spatie\Permission\Models\Role;

class CustomerProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('The configured SQLite test driver is not installed.');
        }

        parent::setUp();
        foreach (['customer', 'service_provider', 'admin'] as $name) {
            Role::create(['name' => $name, 'guard_name' => 'web']);
        }
    }

    public function test_profile_routes_require_customer_authentication(): void
    {
        $this->getJson('/api/customer/profile')->assertUnauthorized();
        $this->putJson('/api/customer/profile', $this->profileData())->assertUnauthorized();
    }

    public function test_non_customers_cannot_access_customer_profiles(): void
    {
        foreach (['service_provider', 'admin'] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);
            $this->actingAs($user)->getJson('/api/customer/profile')->assertForbidden();
            $this->actingAs($user)->putJson('/api/customer/profile', $this->profileData())->assertForbidden();
        }
    }

    public function test_customer_can_create_retrieve_and_update_own_profile(): void
    {
        $customer = $this->customer();
        $this->actingAs($customer)->putJson('/api/customer/profile', $this->profileData(['user_id' => 999]))
            ->assertOk()->assertJsonPath('profile.first_name', 'Ada')->assertJsonMissingPath('profile.user_id')->assertJsonMissingPath('profile.password');
        $this->assertDatabaseHas('customer_profiles', ['user_id' => $customer->id, 'first_name' => 'Ada']);
        $this->actingAs($customer)->getJson('/api/customer/profile')->assertOk()->assertJsonPath('profile.country', 'Sri Lanka');
        $this->actingAs($customer)->putJson('/api/customer/profile', $this->profileData(['city' => 'Kandy']))->assertOk()->assertJsonPath('profile.city', 'Kandy');
        $this->assertSame(1, CustomerProfile::where('user_id', $customer->id)->count());
        $this->assertSame($customer->name, $customer->fresh()->name);
    }

    public function test_profile_requires_complete_bounded_data(): void
    {
        $customer = $this->customer();
        $this->actingAs($customer)->putJson('/api/customer/profile', array_merge($this->profileData(), ['phone' => '', 'address' => str_repeat('a', 501)]))
            ->assertUnprocessable()->assertJsonValidationErrors(['phone', 'address']);
    }

    private function customer(): User
    {
        $customer = User::factory()->create();
        $customer->assignRole('customer');

        return $customer;
    }

    private function profileData(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Ada', 'last_name' => 'Lovelace', 'phone' => '+94770000000', 'address' => '12 Example Road', 'city' => 'Colombo', 'country' => 'Sri Lanka',
        ], $overrides);
    }
}
