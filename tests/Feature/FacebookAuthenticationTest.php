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

class FacebookAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['customer', 'service_provider', 'admin'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_facebook_redirect_is_available_without_exposing_secrets(): void
    {
        Socialite::fake('facebook');

        $this->get('/api/auth/facebook/redirect')
            ->assertRedirect('https://socialite.fake/facebook/authorize')
            ->assertDontSee('FACEBOOK_CLIENT_SECRET');
    }

    public function test_existing_linked_facebook_user_logs_in_without_role_changes(): void
    {
        $user = User::factory()->create();
        $user->assignRole('service_provider');
        $this->socialAccount($user, 'facebook', 'facebook-linked');
        Socialite::fake('facebook', $this->facebookUser('facebook-linked'));

        $response = $this->getJson('/api/auth/facebook/callback?code=valid-code')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.roles.0.name', 'service_provider')
            ->assertJsonMissingPath('password')
            ->assertJsonMissingPath('facebook_access_token');

        $this->assertNotEmpty($response->json('token'));
        $this->assertSame(1, User::count());
        $this->assertSame(1, UserSocialAccount::count());
    }

    public function test_new_facebook_user_is_unverified_customer_only(): void
    {
        Socialite::fake('facebook', $this->facebookUser('facebook-new', 'facebook-new@example.test'));

        $response = $this->getJson('/api/auth/facebook/callback?code=valid-code&role=admin')
            ->assertCreated()
            ->assertJsonPath('user.roles.0.name', 'customer')
            ->assertJsonMissingPath('password')
            ->assertJsonMissingPath('raw_profile');

        $user = User::where('email', 'facebook-new@example.test')->firstOrFail();
        $this->assertTrue($user->hasRole('customer'));
        $this->assertFalse($user->hasRole('admin'));
        $this->assertFalse($user->hasRole('service_provider'));
        $this->assertNotEmpty($user->password);
        $this->assertNull($user->email_verified_at);
        $this->assertDatabaseHas('user_social_accounts', ['user_id' => $user->id, 'provider' => 'facebook', 'provider_user_id' => 'facebook-new']);
        $this->assertNotEmpty($response->json('token'));
    }

    public function test_facebook_without_email_or_with_same_local_email_is_rejected(): void
    {
        Socialite::fake('facebook', $this->facebookUser('facebook-no-email', ''));
        $this->getJson('/api/auth/facebook/callback?code=valid-code')->assertUnprocessable();
        $this->assertSame(0, User::count());

        $user = User::factory()->create(['email' => 'facebook-existing@example.test']);
        Socialite::fake('facebook', $this->facebookUser('facebook-same-email', $user->email));
        $this->getJson('/api/auth/facebook/callback?code=valid-code')
            ->assertConflict()
            ->assertJsonMissingPath('token');
        $this->assertSame(1, User::count());
        $this->assertSame(0, UserSocialAccount::count());
    }

    public function test_authenticated_user_can_link_facebook_and_provider_isolation_allows_matching_google_id(): void
    {
        $user = User::factory()->create();
        $user->assignRole('service_provider');
        $this->socialAccount($user, 'google', 'shared-provider-id');
        Socialite::fake('facebook', $this->facebookUser('shared-provider-id'));

        $this->withToken($user->createToken('api-token')->plainTextToken)
            ->get('/api/auth/facebook/link')
            ->assertRedirect('https://socialite.fake/facebook/authorize');

        $this->withSession(['facebook_link_user_id' => $user->id])
            ->getJson('/api/auth/facebook/callback?code=valid-code')
            ->assertOk()
            ->assertJsonMissingPath('token');

        $this->assertTrue($user->fresh()->hasRole('service_provider'));
        $this->assertDatabaseHas('user_social_accounts', ['user_id' => $user->id, 'provider' => 'facebook', 'provider_user_id' => 'shared-provider-id']);
    }

    public function test_facebook_linking_and_failure_paths_are_rejected_safely(): void
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $this->socialAccount($firstUser, 'facebook', 'facebook-linked-elsewhere');
        Socialite::fake('facebook', $this->facebookUser('facebook-linked-elsewhere'));
        $this->withSession(['facebook_link_user_id' => $secondUser->id])
            ->getJson('/api/auth/facebook/callback?code=valid-code')
            ->assertConflict();

        Socialite::fake('facebook', static function (): void {
            throw new InvalidStateException;
        });
        $this->getJson('/api/auth/facebook/callback?code=valid-code')->assertForbidden();
        $this->getJson('/api/auth/facebook/callback?error=access_denied')->assertUnprocessable();
        Socialite::fake('facebook', $this->facebookUser('', 'missing-id@example.test'));
        $this->getJson('/api/auth/facebook/callback?code=valid-code')->assertUnprocessable();
    }

    public function test_facebook_identity_uniqueness_is_enforced(): void
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $this->socialAccount($firstUser, 'facebook', 'facebook-unique');

        $this->expectException(QueryException::class);
        $this->socialAccount($secondUser, 'facebook', 'facebook-unique');
    }

    private function facebookUser(string $id, string $email = 'facebook@example.test'): SocialiteUser
    {
        return SocialiteUser::fake(['id' => $id, 'name' => 'Facebook Test User', 'email' => $email, 'token' => 'facebook-access-token']);
    }

    private function socialAccount(User $user, string $provider, string $providerUserId): UserSocialAccount
    {
        return UserSocialAccount::create(['user_id' => $user->id, 'provider' => $provider, 'provider_user_id' => $providerUserId]);
    }
}
