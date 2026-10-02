<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_unverified_user_can_request_an_email_verification_link(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->withToken($user->createToken('test-token')->plainTextToken)
            ->postJson('/api/auth/email/verification-notification')
            ->assertOk()
            ->assertJsonPath('message', 'If email verification is needed, a verification link has been sent.');

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_verification_resend_requires_authentication_and_is_rate_limited(): void
    {
        Notification::fake();
        $this->postJson('/api/auth/email/verification-notification')->assertUnauthorized();

        $user = User::factory()->unverified()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->withToken($token)->postJson('/api/auth/email/verification-notification')->assertOk();
        }

        $this->withToken($token)->postJson('/api/auth/email/verification-notification')->assertTooManyRequests();
    }

    public function test_already_verified_user_can_safely_request_a_verification_link_without_receiving_one(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->withToken($user->createToken('test-token')->plainTextToken)
            ->postJson('/api/auth/email/verification-notification')
            ->assertOk();

        Notification::assertNothingSent();
    }

    public function test_signed_verification_link_marks_an_email_as_verified_and_is_idempotent(): void
    {
        $user = User::factory()->unverified()->create();
        $url = $this->verificationUrl($user);

        $this->getJson($url)->assertOk()->assertJsonPath('message', 'Email verified successfully.')->assertJsonMissingPath('user');
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->getJson($url)->assertOk();
    }

    public function test_invalid_expired_and_wrong_hash_verification_links_are_rejected(): void
    {
        $user = User::factory()->unverified()->create();

        $this->getJson($this->verificationUrl($user).'invalid')->assertForbidden();

        $expiredUrl = URL::temporarySignedRoute('verification.verify', now()->subMinute(), [
            'user' => $user,
            'hash' => sha1($user->getEmailForVerification()),
        ]);
        $this->getJson($expiredUrl)->assertForbidden();

        $wrongHashUrl = URL::temporarySignedRoute('verification.verify', now()->addMinutes(5), [
            'user' => $user,
            'hash' => sha1('other@example.test'),
        ]);
        $this->getJson($wrongHashUrl)->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_verification_status_is_available_without_exposing_sensitive_fields(): void
    {
        $user = User::factory()->unverified()->create();

        $this->withToken($user->createToken('test-token')->plainTextToken)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.email_verified_at', null)
            ->assertJsonMissingPath('user.password')
            ->assertJsonMissingPath('user.remember_token');
    }

    public function test_unverified_users_can_still_log_in_and_access_existing_authenticated_routes(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'unverified@example.test', 'password' => 'password']);

        $response = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();

        $this->withToken($response->json('token'))->getJson('/api/auth/me')->assertOk();
    }

    public function test_forgot_password_validates_email_and_does_not_reveal_account_existence(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'member@example.test']);

        $this->postJson('/api/auth/forgot-password', ['email' => 'not-an-email'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $existing = $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();
        $missing = $this->postJson('/api/auth/forgot-password', ['email' => 'missing@example.test'])->assertOk();

        $this->assertSame($existing->json(), $missing->json());
        $this->assertStringNotContainsString('token', $existing->getContent());
    }

    public function test_forgot_password_is_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/auth/forgot-password', ['email' => 'missing@example.test'])->assertOk();
        }

        $this->postJson('/api/auth/forgot-password', ['email' => 'missing@example.test'])->assertTooManyRequests();
    }

    public function test_valid_password_reset_changes_the_password_revokes_tokens_and_consumes_the_token(): void
    {
        $user = User::factory()->create(['email' => 'reset@example.test', 'password' => 'password']);
        $user->createToken('first-token');
        $user->createToken('second-token');
        $token = Password::broker()->createToken($user);
        $payload = $this->resetPayload($user, $token, 'new-secure-password');

        $this->postJson('/api/auth/reset-password', $payload)
            ->assertOk()
            ->assertJsonPath('message', 'Password reset successfully. Please log in again.')
            ->assertJsonMissingPath('token');
        $this->assertSame(0, $user->fresh()->tokens()->count());
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->assertUnauthorized();
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'new-secure-password'])->assertOk();
        $this->postJson('/api/auth/reset-password', $payload)->assertUnprocessable();
    }

    public function test_invalid_or_expired_reset_tokens_do_not_revoke_existing_tokens(): void
    {
        $user = User::factory()->create(['email' => 'expired@example.test']);
        $user->createToken('existing-token');

        $this->postJson('/api/auth/reset-password', $this->resetPayload($user, 'invalid-token', 'new-secure-password'))->assertUnprocessable();
        $this->assertSame(1, $user->fresh()->tokens()->count());

        $token = Password::broker()->createToken($user);
        Carbon::setTestNow(now()->addMinutes(61));

        try {
            $this->postJson('/api/auth/reset-password', $this->resetPayload($user, $token, 'new-secure-password'))->assertUnprocessable();
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame(1, $user->fresh()->tokens()->count());
    }

    public function test_password_reset_enforces_confirmation_and_password_rules(): void
    {
        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'short',
            'password_confirmation' => 'different',
        ])->assertUnprocessable()->assertJsonValidationErrors(['password']);
    }

    public function test_reset_password_submission_is_rate_limited_without_mutating_accounts_or_tokens(): void
    {
        $user = User::factory()->create(['email' => 'limited@example.test', 'password' => 'password']);
        $user->createToken('first-token');
        $user->createToken('second-token');
        $invalidPayload = $this->resetPayload($user, 'invalid-token', 'new-secure-password');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/auth/reset-password', $invalidPayload)->assertUnprocessable();
        }

        $this->postJson('/api/auth/reset-password', $invalidPayload)->assertTooManyRequests();
        $this->assertSame(2, $user->fresh()->tokens()->count());
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
    }

    public function test_reset_password_submission_throttle_is_scoped_to_the_requested_email(): void
    {
        $limitedUser = User::factory()->create(['email' => 'limited@example.test']);
        $otherUser = User::factory()->create(['email' => 'other@example.test']);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/auth/reset-password', $this->resetPayload($limitedUser, 'invalid-token', 'new-secure-password'))
                ->assertUnprocessable();
        }

        $token = Password::broker()->createToken($otherUser);

        $this->postJson('/api/auth/reset-password', $this->resetPayload($otherUser, $token, 'new-secure-password'))
            ->assertOk();
    }

    public function test_password_change_requires_authentication_and_validates_its_inputs(): void
    {
        $this->putJson('/api/auth/password', [])->assertUnauthorized();

        $user = User::factory()->create();
        $token = $user->createToken('current-token')->plainTextToken;

        $this->withToken($token)->putJson('/api/auth/password', [])->assertUnprocessable()->assertJsonValidationErrors(['current_password', 'password']);
        $this->withToken($token)->putJson('/api/auth/password', [
            'current_password' => 'password',
            'password' => 'short',
            'password_confirmation' => 'different',
        ])->assertUnprocessable()->assertJsonValidationErrors(['password']);
        $this->assertSame(1, $user->fresh()->tokens()->count());
    }

    public function test_password_change_rejects_an_incorrect_current_password_without_revoking_tokens(): void
    {
        $user = User::factory()->create();
        $currentToken = $user->createToken('current-token')->plainTextToken;
        $user->createToken('other-token');

        $this->withToken($currentToken)->putJson('/api/auth/password', [
            'current_password' => 'incorrect-password',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ])->assertUnprocessable()->assertJsonValidationErrors(['current_password']);

        $this->assertSame(2, $user->fresh()->tokens()->count());
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_password_change_preserves_the_current_token_and_revokes_only_other_user_tokens(): void
    {
        $user = User::factory()->create(['email' => 'change@example.test', 'password' => 'password']);
        $currentToken = $user->createToken('current-token')->plainTextToken;
        $otherToken = $user->createToken('other-token')->plainTextToken;
        $otherUser = User::factory()->create();
        $otherUser->createToken('unrelated-token');

        $this->withToken($currentToken)->putJson('/api/auth/password', [
            'current_password' => 'password',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ])->assertOk()
            ->assertJsonPath('message', 'Password changed successfully.')
            ->assertJsonMissingPath('password')
            ->assertJsonMissingPath('token')
            ->assertJsonMissingPath('user');

        $this->assertTrue(Hash::check('new-secure-password', $user->fresh()->password));
        $this->assertSame(1, $user->fresh()->tokens()->count());
        $this->assertSame(1, $otherUser->fresh()->tokens()->count());
        $this->assertNotNull(PersonalAccessToken::findToken($currentToken));
        $this->assertNull(PersonalAccessToken::findToken($otherToken));

        $this->withToken($currentToken)->getJson('/api/auth/me')->assertOk();
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->assertUnauthorized();
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'new-secure-password'])->assertOk();
    }

    private function verificationUrl(User $user): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addMinutes(5), [
            'user' => $user,
            'hash' => sha1($user->getEmailForVerification()),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function resetPayload(User $user, string $token, string $password): array
    {
        return [
            'email' => $user->email,
            'token' => $token,
            'password' => $password,
            'password_confirmation' => $password,
        ];
    }
}
