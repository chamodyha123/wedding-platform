<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserSocialAccount;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GoogleAuthenticationTest extends TestCase
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

    public function test_google_redirect_route_starts_the_authorization_flow_without_exposing_credentials(): void
    {
        Socialite::fake('google');

        $this->get('/api/auth/google/redirect')
            ->assertRedirect('https://socialite.fake/google/authorize')
            ->assertDontSee('GOOGLE_CLIENT_SECRET');
    }

    public function test_existing_linked_google_user_receives_a_sanctum_token_without_role_changes(): void
    {
        $user = User::factory()->create();
        $user->assignRole('service_provider');
        $this->socialAccount($user, 'google-linked-user');
        Socialite::fake('google', $this->googleUser('google-linked-user'));

        $response = $this->getJson('/api/auth/google/callback?code=valid-code')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.roles.0.name', 'service_provider')
            ->assertJsonMissingPath('password')
            ->assertJsonMissingPath('token_hash');

        $this->assertNotEmpty($response->json('token'));
        $this->assertSame(1, User::count());
        $this->assertSame(1, UserSocialAccount::count());
        $this->assertTrue($user->fresh()->hasRole('service_provider'));
    }

    public function test_new_google_user_is_a_customer_with_a_social_identity_and_verified_email_when_google_confirms_it(): void
    {
        Socialite::fake('google', $this->googleUser(
            'google-new-user',
            'new-google@example.test',
            true
        ));

        $response = $this->getJson('/api/auth/google/callback?code=valid-code&role=admin')
            ->assertCreated()
            ->assertJsonPath('user.email', 'new-google@example.test')
            ->assertJsonPath('user.roles.0.name', 'customer')
            ->assertJsonMissingPath('password')
            ->assertJsonMissingPath('google_access_token')
            ->assertJsonMissingPath('raw_profile');

        $user = User::where('email', 'new-google@example.test')->firstOrFail();

        $this->assertTrue($user->hasRole('customer'));
        $this->assertFalse($user->hasRole('admin'));
        $this->assertFalse($user->hasRole('service_provider'));
        $this->assertNotEmpty($user->password);
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseHas('user_social_accounts', [
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_user_id' => 'google-new-user',
        ]);
        $this->assertNotEmpty($response->json('token'));
    }

    public function test_new_google_user_with_an_unverified_google_email_remains_unverified(): void
    {
        Socialite::fake('google', $this->googleUser(
            'google-unverified-email',
            'unverified-google@example.test',
            false
        ));

        $this->getJson('/api/auth/google/callback?code=valid-code')->assertCreated();

        $this->assertNull(
            User::where('email', 'unverified-google@example.test')
                ->firstOrFail()
                ->email_verified_at
        );
    }

    public function test_google_callback_rejects_new_users_without_an_email(): void
    {
        Socialite::fake('google', $this->googleUser(
            'google-no-email',
            ''
        ));

        $this->getJson('/api/auth/google/callback?code=valid-code')
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'Google did not provide an email required for account creation.'
            );

        $this->assertSame(0, User::count());
        $this->assertSame(0, UserSocialAccount::count());
    }

    public function test_same_email_local_account_is_neither_linked_nor_logged_in(): void
    {
        $localUser = User::factory()->create([
            'email' => 'existing-local@example.test',
        ]);
        Socialite::fake('google', $this->googleUser(
            'google-same-email',
            $localUser->email,
            true
        ));

        $this->getJson('/api/auth/google/callback?code=valid-code')
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'Sign in to your existing account before linking Google.'
            )
            ->assertJsonMissingPath('token');

        $this->assertSame(1, User::count());
        $this->assertSame(0, UserSocialAccount::count());
    }

    public function test_google_callback_rejects_missing_provider_identity_and_provider_failures(): void
    {
        Socialite::fake('google', $this->googleUser('', 'missing-id@example.test'));

        $this->getJson('/api/auth/google/callback?code=valid-code')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Google did not provide a valid identity.');

        Socialite::fake('google', static function (): void {
            throw new \RuntimeException('Provider failure');
        });

        $this->getJson('/api/auth/google/callback?code=valid-code')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Google authentication could not be completed.');
    }

    public function test_google_callback_rejects_invalid_state_and_cancelled_authorization(): void
    {
        Socialite::fake('google', static function (): void {
            throw new InvalidStateException;
        });

        $this->getJson('/api/auth/google/callback?code=valid-code')
            ->assertForbidden()
            ->assertJsonPath('message', 'Google authentication state is invalid or expired.');

        $this->getJson('/api/auth/google/callback?error=access_denied')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Google authentication was not completed.');
    }

    public function test_authenticated_user_can_link_a_new_google_identity_without_changing_roles(): void
    {
        $user = User::factory()->create();
        $user->assignRole('service_provider');
        Socialite::fake('google', $this->googleUser('google-link-user'));

        $this->withToken($user->createToken('api-token')->plainTextToken)
            ->get('/api/auth/google/link')
            ->assertRedirect('https://socialite.fake/google/authorize');

        $this->withSession(['google_link_user_id' => $user->id])
            ->getJson('/api/auth/google/callback?code=valid-code')
            ->assertOk()
            ->assertJsonPath('message', 'Google identity linked successfully.')
            ->assertJsonMissingPath('token');

        $this->assertTrue($user->fresh()->hasRole('service_provider'));
        $this->assertDatabaseHas('user_social_accounts', [
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_user_id' => 'google-link-user',
        ]);
    }

    public function test_google_linking_rejects_an_identity_or_provider_already_linked_elsewhere(): void
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $this->socialAccount($firstUser, 'google-already-linked');
        Socialite::fake('google', $this->googleUser('google-already-linked'));

        $this->withSession(['google_link_user_id' => $secondUser->id])
            ->getJson('/api/auth/google/callback?code=valid-code')
            ->assertConflict();

        Socialite::fake('google', $this->googleUser('google-second-link'));
        $this->socialAccount($secondUser, 'google-existing-provider');

        $this->withSession(['google_link_user_id' => $secondUser->id])
            ->getJson('/api/auth/google/callback?code=valid-code')
            ->assertConflict();
    }

    public function test_social_identity_constraints_and_user_deletion_cascade_are_enforced(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $this->socialAccount($user, 'google-unique-provider');

        try {
            $this->socialAccount($otherUser, 'google-unique-provider');
            $this->fail('The provider identity unique constraint was not enforced.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        try {
            $this->socialAccount($user, 'google-duplicate-provider');
            $this->fail('The user/provider unique constraint was not enforced.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $user->delete();

        $this->assertDatabaseMissing('user_social_accounts', [
            'user_id' => $user->id,
        ]);
    }

    private function googleUser(
        string $id,
        string $email = 'google-user@example.test',
        bool $emailVerified = true
    ): SocialiteUser {
        return SocialiteUser::fake([
            'id' => $id,
            'name' => 'Google Test User',
            'email' => $email,
            'email_verified' => $emailVerified,
            'token' => 'google-access-token',
            'refreshToken' => 'google-refresh-token',
        ]);
    }

    private function socialAccount(User $user, string $providerUserId): UserSocialAccount
    {
        return UserSocialAccount::create([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_user_id' => $providerUserId,
        ]);
    }
}
