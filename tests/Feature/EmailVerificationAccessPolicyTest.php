<?php

namespace Tests\Feature;

use App\Mail\RegistrationOtpMail;
use App\Models\Booking;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmailVerificationAccessPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['customer', 'service_provider', 'admin'] as $role) {
            Role::create([
                'name' => $role,
                'guard_name' => 'web',
            ]);
        }
    }

    public function test_unverified_customers_are_denied_every_restricted_operation_as_json(): void
    {
        $customer = $this->userWithRole('customer', false);
        $token = $customer->createToken('test')->plainTextToken;

        $requests = [
            fn () => $this->withToken($token)->postJson('/api/customer/bookings'),
            fn () => $this->withToken($token)->postJson('/api/customer/bookings/1/reviews'),
            fn () => $this->withToken($token)->putJson('/api/customer/reviews/1'),
            fn () => $this->withToken($token)->deleteJson('/api/customer/reviews/1'),
            fn () => $this->withToken($token)->postJson('/api/customer/bookings/1/payments'),
            fn () => $this->withToken($token)->postJson('/api/customer/bookings/1/payments/1/success'),
            fn () => $this->withToken($token)->postJson('/api/customer/bookings/1/payments/1/fail'),
            fn () => $this->withToken($token)->postJson('/api/customer/bookings/1/payments/1/cancel'),
        ];

        foreach ($requests as $request) {
            $request()
                ->assertForbidden()
                ->assertJsonPath('message', 'Your email address is not verified.');
        }
    }

    public function test_verified_customers_reach_existing_validation_and_authorization_logic(): void
    {
        $customer = $this->userWithRole('customer');
        $token = $customer->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson('/api/customer/bookings')
            ->assertUnprocessable();
        $this->withToken($token)->postJson('/api/customer/bookings/1/payments')
            ->assertUnprocessable();
        $this->withToken($token)->postJson('/api/customer/bookings/1/reviews', ['rating' => 5])
            ->assertNotFound();
        $this->withToken($token)->putJson('/api/customer/reviews/1', ['rating' => 5])
            ->assertNotFound();
        $this->withToken($token)->deleteJson('/api/customer/reviews/1')
            ->assertNotFound();
    }

    public function test_unverified_customers_keep_cancellation_onboarding_history_and_security_access(): void
    {
        $customer = $this->userWithRole('customer', false);
        $booking = $this->bookingFor($customer);
        $token = $customer->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson('/api/customer/bookings/'.$booking->id.'/cancel', [
            'cancellation_reason' => 'Plans changed.',
        ])->assertOk();

        $this->withToken($token)->putJson('/api/customer/profile', $this->profileData())
            ->assertOk();
        $this->withToken($token)->getJson('/api/customer/bookings')->assertOk();
        $this->withToken($token)->getJson('/api/customer/payments')->assertOk();
        $this->withToken($token)->getJson('/api/customer/reviews')->assertOk();
        $this->withToken($token)->getJson('/api/notifications')->assertOk();
        $this->withToken($token)->getJson('/api/auth/me')->assertOk();
        $this->withToken($token)->putJson('/api/auth/password')->assertUnprocessable();
    }

    public function test_registration_otp_unlocks_restricted_customer_operations(): void
    {
        Mail::fake();
        $customer = $this->userWithRole('customer', false);
        $token = $customer->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson('/api/customer/bookings')
            ->assertForbidden();
        $this->withToken($token)->postJson('/api/auth/email/otp/send')
            ->assertOk();

        Mail::assertSent(RegistrationOtpMail::class, function (RegistrationOtpMail $mail) use (&$code): bool {
            $code = $mail->code;

            return true;
        });

        $this->withToken($token)->postJson('/api/auth/email/otp/verify', ['code' => $code])
            ->assertOk();
        $this->withToken($token)->postJson('/api/customer/bookings')
            ->assertUnprocessable();
    }

    public function test_google_verified_users_are_allowed_to_reach_existing_business_validation(): void
    {
        Socialite::fake('google', $this->socialUser('google-verified', 'google@example.test', true));
        $googleToken = $this->getJson('/api/auth/google/callback?code=valid-code')
            ->assertCreated()
            ->json('token');

        $this->withToken($googleToken)->postJson('/api/customer/bookings')
            ->assertUnprocessable();
    }

    public function test_facebook_users_remain_restricted_until_local_email_verification(): void
    {
        Mail::fake();
        Socialite::fake('facebook', $this->socialUser('facebook-unverified', 'facebook@example.test', false));
        $facebookToken = $this->getJson('/api/auth/facebook/callback?code=valid-code')
            ->assertCreated()
            ->json('token');

        $this->withToken($facebookToken)->postJson('/api/customer/bookings')
            ->assertForbidden();
        $this->withToken($facebookToken)->postJson('/api/auth/email/otp/send')
            ->assertOk();

        Mail::assertSent(RegistrationOtpMail::class, function (RegistrationOtpMail $mail) use (&$code): bool {
            $code = $mail->code;

            return true;
        });

        $this->withToken($facebookToken)->postJson('/api/auth/email/otp/verify', ['code' => $code])
            ->assertOk();
        $this->withToken($facebookToken)->postJson('/api/customer/bookings')
            ->assertUnprocessable();
    }

    public function test_unverified_providers_are_denied_mutations_but_can_read_availability(): void
    {
        $providerUser = $this->userWithRole('service_provider', false);
        $provider = $this->providerFor($providerUser, 'verified');
        $service = $this->serviceFor($provider);
        $token = $providerUser->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/provider/services/'.$service->id.'/availability')
            ->assertOk();

        $requests = [
            fn () => $this->withToken($token)->postJson('/api/provider/bookings/1/accept'),
            fn () => $this->withToken($token)->postJson('/api/provider/bookings/1/reject'),
            fn () => $this->withToken($token)->postJson('/api/provider/bookings/1/complete'),
            fn () => $this->withToken($token)->postJson('/api/provider/services'),
            fn () => $this->withToken($token)->putJson('/api/provider/services/1'),
            fn () => $this->withToken($token)->deleteJson('/api/provider/services/1'),
            fn () => $this->withToken($token)->postJson('/api/provider/services/1/packages'),
            fn () => $this->withToken($token)->putJson('/api/provider/services/1/packages/1'),
            fn () => $this->withToken($token)->deleteJson('/api/provider/services/1/packages/1'),
            fn () => $this->withToken($token)->postJson('/api/provider/services/1/availability'),
            fn () => $this->withToken($token)->putJson('/api/provider/services/1/availability/1'),
            fn () => $this->withToken($token)->deleteJson('/api/provider/services/1/availability/1'),
        ];

        foreach ($requests as $request) {
            $request()->assertForbidden()
                ->assertJsonPath('message', 'Your email address is not verified.');
        }
    }

    public function test_provider_business_verification_and_role_checks_remain_independent(): void
    {
        $providerUser = $this->userWithRole('service_provider');
        $this->providerFor($providerUser, 'pending');
        $providerToken = $providerUser->createToken('test')->plainTextToken;

        $this->withToken($providerToken)->postJson('/api/provider/services')
            ->assertForbidden()
            ->assertJsonPath('message', 'Your business must be verified before you can create services.');

        $customer = $this->userWithRole('customer', false);
        $customerToken = $customer->createToken('test')->plainTextToken;
        $this->withToken($customerToken)->postJson('/api/provider/services')
            ->assertForbidden();
    }

    public function test_admin_and_payment_callback_routes_remain_unaffected(): void
    {
        $admin = $this->userWithRole('admin', false);
        $adminToken = $admin->createToken('test')->plainTextToken;

        $this->withToken($adminToken)->getJson('/api/admin/dashboard')
            ->assertOk();
        $this->withToken($adminToken)->postJson('/api/admin/payments/999999/reconcile')
            ->assertNotFound();
        $this->postJson('/api/payments/payhere/notify')
            ->assertUnprocessable();
    }

    private function userWithRole(string $role, bool $verified = true): User
    {
        $user = User::factory()->create([
            'email_verified_at' => $verified ? now() : null,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function providerFor(User $user, string $status): ServiceProvider
    {
        return ServiceProvider::create([
            'user_id' => $user->id,
            'business_name' => 'Provider '.$user->id,
            'business_slug' => 'provider-'.$user->id,
            'verification_status' => $status,
            'is_active' => true,
        ]);
    }

    private function serviceFor(ServiceProvider $provider): Service
    {
        $category = ServiceCategory::create([
            'name' => 'Category '.$provider->id,
            'slug' => 'category-'.$provider->id,
        ]);

        return Service::create([
            'service_provider_id' => $provider->id,
            'service_category_id' => $category->id,
            'name' => 'Service '.$provider->id,
            'slug' => 'service-'.$provider->id,
            'status' => 'published',
        ]);
    }

    private function bookingFor(User $customer): Booking
    {
        $providerUser = $this->userWithRole('service_provider');
        $provider = $this->providerFor($providerUser, 'verified');
        $service = $this->serviceFor($provider);

        return Booking::create([
            'booking_reference' => 'BK-'.$customer->id,
            'customer_id' => $customer->id,
            'service_provider_id' => $provider->id,
            'service_id' => $service->id,
            'event_date' => now()->addDay()->toDateString(),
            'start_time' => '10:00',
            'end_time' => '11:00',
            'total_amount' => 100,
            'booking_status' => 'pending',
            'payment_status' => 'unpaid',
        ]);
    }

    /** @return array<string, string> */
    private function profileData(): array
    {
        return [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'phone' => '+94770000000',
            'address' => '12 Example Road',
            'city' => 'Colombo',
            'country' => 'Sri Lanka',
        ];
    }

    private function socialUser(string $id, string $email, bool $emailVerified): SocialiteUser
    {
        return SocialiteUser::fake([
            'id' => $id,
            'name' => 'Social Test User',
            'email' => $email,
            'email_verified' => $emailVerified,
            'token' => 'access-token',
        ]);
    }
}
