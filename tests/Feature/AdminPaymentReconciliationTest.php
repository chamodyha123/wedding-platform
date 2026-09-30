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
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminPaymentReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconciliation_requires_an_authenticated_admin(): void
    {
        [, , $payment] = $this->fixture('auth');
        $this->postJson('/api/admin/payments/'.$payment->id.'/reconcile')->assertUnauthorized();

        foreach (['customer', 'service_provider'] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);
            $this->actingAs($user)->postJson('/api/admin/payments/'.$payment->id.'/reconcile')->assertForbidden();
        }
    }

    public function test_admin_receives_not_found_and_non_payhere_is_rejected_without_http(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->postJson('/api/admin/payments/999999/reconcile')->assertNotFound();

        [, , $payment] = $this->fixture('non-payhere');
        $payment->update(['gateway' => null]);
        Http::preventStrayRequests();
        $this->actingAs($admin)->postJson('/api/admin/payments/'.$payment->id.'/reconcile')
            ->assertUnprocessable()
            ->assertJson(['message' => 'This payment cannot be reconciled through PayHere.']);
        Http::assertNothingSent();
    }

    public function test_admin_can_reconcile_a_received_payment_and_response_is_sanitized(): void
    {
        $admin = $this->admin();
        [, $booking, $payment] = $this->fixture('success');
        $this->fakeReceived($payment);

        $response = $this->actingAs($admin)->postJson('/api/admin/payments/'.$payment->id.'/reconcile');
        $response->assertOk()->assertJson([
            'payment_id' => $payment->id,
            'payment_reference' => $payment->payment_reference,
            'outcome' => 'reconciled',
            'previous_status' => 'pending',
            'current_status' => 'paid',
            'gateway_status' => 'RECEIVED',
        ]);
        foreach (['access_token', 'app_secret', 'merchant_secret', 'Authorization', 'raw_response', 'private@example.test', '+94770000000', '1 Test Road', '4111111111111111'] as $forbidden) {
            $response->assertDontSee($forbidden);
        }
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'paid', 'gateway_transaction_id' => 'TX-SUCCESS']);
        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'booking_status' => 'confirmed', 'payment_status' => 'paid']);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_contains($request->url(), '/oauth/token'));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && str_contains($request->url(), '/payment/search'));
    }

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        foreach (['customer', 'service_provider', 'admin'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }
        config(['services.payhere.mode' => 'sandbox', 'services.payhere.app_id' => 'test-app-id', 'services.payhere.app_secret' => 'test-app-secret']);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    /** @return array{0: User, 1: Booking, 2: Payment} */
    private function fixture(string $suffix): array
    {
        $customer = User::factory()->create(['email' => $suffix.'@example.test']);
        $customer->assignRole('customer');
        $providerUser = User::factory()->create();
        $providerUser->assignRole('service_provider');
        $provider = ServiceProvider::create(['user_id' => $providerUser->id, 'business_name' => 'Provider '.$suffix, 'business_slug' => 'provider-'.$suffix, 'verification_status' => 'verified', 'is_active' => true]);
        $category = ServiceCategory::create(['name' => 'Category '.$suffix, 'slug' => 'category-'.$suffix]);
        $service = Service::create(['service_provider_id' => $provider->id, 'service_category_id' => $category->id, 'name' => 'Service '.$suffix, 'slug' => 'service-'.$suffix, 'status' => 'published']);
        $package = ServicePackage::create(['service_id' => $service->id, 'name' => 'Package '.$suffix, 'slug' => 'package-'.$suffix, 'price' => 100, 'status' => 'published']);
        $booking = Booking::create(['booking_reference' => 'BK-'.$suffix, 'customer_id' => $customer->id, 'service_provider_id' => $provider->id, 'service_id' => $service->id, 'service_package_id' => $package->id, 'event_date' => now()->addDays(3)->toDateString(), 'start_time' => '10:00', 'end_time' => '11:00', 'event_location' => 'Test', 'total_amount' => 100, 'booking_status' => 'accepted', 'payment_status' => 'pending']);
        $payment = Payment::create(['booking_id' => $booking->id, 'customer_id' => $customer->id, 'payment_reference' => 'PAY-'.$suffix, 'amount' => 100, 'currency' => 'LKR', 'payment_method' => 'card', 'status' => 'pending', 'gateway' => 'payhere']);

        return [$customer, $booking, $payment];
    }

    private function fakeReceived(Payment $payment): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*oauth/token' => Http::response(['access_token' => 'private-token', 'expires_in' => 599]),
            '*payment/search*' => Http::response(['data' => [[
                'order_id' => $payment->payment_reference,
                'payment_id' => 'TX-SUCCESS',
                'status' => 'RECEIVED',
                'amount' => '100.00',
                'currency' => 'LKR',
                'customer' => ['email' => 'private@example.test', 'phone' => '+94770000000', 'address' => '1 Test Road'],
                'payment_method' => ['card_number' => '4111111111111111'],
            ]]]),
        ]);
    }
}
