<?php

namespace Tests\Feature;

use App\AccountSecurityOtpPurpose;
use App\Mail\PasswordResetOtpMail;
use App\Models\AccountSecurityOtp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetOtpTest extends TestCase
{
    use RefreshDatabase;

    public function test_issuance_is_enumeration_safe_and_only_existing_users_receive_mail(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'member@example.test']);

        $existing = $this->postJson('/api/auth/password/otp/send', ['email' => 'MEMBER@example.test'])->assertOk()->assertJsonMissingPath('code')->assertJsonMissingPath('token')->assertJsonMissingPath('user');
        $missing = $this->postJson('/api/auth/password/otp/send', ['email' => 'missing@example.test'])->assertOk()->assertJsonMissingPath('code')->assertJsonMissingPath('token')->assertJsonMissingPath('user');

        $this->assertSame($existing->json(), $missing->json());
        Mail::assertSent(PasswordResetOtpMail::class, 1);
        $this->assertDatabaseMissing('account_security_otps', ['email' => 'missing@example.test']);

        $otp = AccountSecurityOtp::firstOrFail();
        $this->assertSame($user->id, $otp->user_id);
        $this->assertSame('member@example.test', $otp->email);
        $this->assertSame(AccountSecurityOtpPurpose::PasswordReset->value, $otp->purpose);
        $this->assertSame(0, $otp->attempt_count);
        $this->assertGreaterThan(590, now()->diffInSeconds($otp->expires_at, false));
    }

    public function test_issued_code_is_six_digits_and_is_hashed_at_rest_without_notifications(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        $this->sendCode($user);

        $code = $this->latestCode();
        $otp = AccountSecurityOtp::firstOrFail();
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertNotSame($code, $otp->code_hash);
        $this->assertTrue(Hash::check($code, $otp->code_hash));
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_cooldown_response_is_generic_and_does_not_replace_an_active_code(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'cooldown@example.test']);

        $first = $this->postJson('/api/auth/password/otp/send', ['email' => $user->email])->assertOk();
        $otp = AccountSecurityOtp::firstOrFail();
        $cooldown = $this->postJson('/api/auth/password/otp/send', ['email' => $user->email])->assertOk();
        $missing = $this->postJson('/api/auth/password/otp/send', ['email' => 'missing-cooldown@example.test'])->assertOk();

        $this->assertSame($first->json(), $cooldown->json());
        $this->assertSame($first->json(), $missing->json());
        $this->assertSame(1, AccountSecurityOtp::count());
        $this->assertNull($otp->fresh()->invalidated_at);
        Mail::assertSent(PasswordResetOtpMail::class, 1);
    }

    public function test_replacement_invalidates_only_password_reset_otp_after_cooldown(): void
    {
        Mail::fake();
        $user = User::factory()->unverified()->create();
        $registrationOtp = AccountSecurityOtp::create([
            'user_id' => $user->id,
            'email' => $user->email,
            'purpose' => AccountSecurityOtpPurpose::Registration->value,
            'code_hash' => Hash::make('111111'),
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->sendCode($user);
        $first = AccountSecurityOtp::where('purpose', AccountSecurityOtpPurpose::PasswordReset->value)->firstOrFail();
        $firstCode = $this->latestCode();
        $first->forceFill(['created_at' => now()->subSeconds(61)])->save();

        $this->sendCode($user);

        $this->assertNotNull($first->fresh()->invalidated_at);
        $this->assertNull($registrationOtp->fresh()->invalidated_at);
        $this->resetWithOtp($user->email, $firstCode)->assertUnprocessable();
    }

    public function test_successful_reset_consumes_code_revokes_tokens_and_invalidates_broker_token(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'reset@example.test', 'password' => 'password']);
        $user->createToken('first-token');
        $user->createToken('second-token');
        $brokerToken = Password::broker()->createToken($user);

        $this->sendCode($user);
        $code = $this->latestCode();
        $response = $this->resetWithOtp($user->email, $code, 'new-secure-password');

        $response->assertOk()->assertJsonMissingPath('code')->assertJsonMissingPath('token')->assertJsonMissingPath('password');
        $this->assertTrue(Hash::check('new-secure-password', $user->fresh()->password));
        $this->assertSame(0, $user->fresh()->tokens()->count());
        $this->assertFalse(Password::broker()->tokenExists($user->fresh(), $brokerToken));
        $this->assertNotNull(AccountSecurityOtp::firstOrFail()->fresh()->consumed_at);
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->assertUnauthorized();
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'new-secure-password'])->assertOk();
        $this->resetWithOtp($user->email, $code, 'another-secure-password')->assertUnprocessable();
    }

    public function test_wrong_codes_increment_attempts_and_exhaust_without_mutating_the_account(): void
    {
        Mail::fake();
        $user = User::factory()->create(['password' => 'password']);
        $user->createToken('existing-token');

        $this->sendCode($user);
        $code = $this->latestCode();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->resetWithOtp($user->email, '000000')->assertUnprocessable();
        }

        $otp = AccountSecurityOtp::firstOrFail()->fresh();
        $this->assertSame(5, $otp->attempt_count);
        $this->assertNotNull($otp->invalidated_at);
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
        $this->assertSame(1, $user->fresh()->tokens()->count());
        $this->resetWithOtp($user->email, $code)->assertUnprocessable();
    }

    public function test_expired_invalidated_and_consumed_codes_are_rejected(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        $this->sendCode($user);
        $expiredCode = $this->latestCode();
        AccountSecurityOtp::firstOrFail()->update(['expires_at' => now()->subSecond()]);
        $this->resetWithOtp($user->email, $expiredCode)->assertUnprocessable();

        AccountSecurityOtp::query()->delete();
        $this->sendCode($user);
        $invalidatedCode = $this->latestCode();
        AccountSecurityOtp::firstOrFail()->update(['invalidated_at' => now()]);
        $this->resetWithOtp($user->email, $invalidatedCode)->assertUnprocessable();

        AccountSecurityOtp::query()->delete();
        $this->sendCode($user);
        $consumedCode = $this->latestCode();
        AccountSecurityOtp::firstOrFail()->update(['consumed_at' => now()]);
        $this->resetWithOtp($user->email, $consumedCode)->assertUnprocessable();
    }

    public function test_codes_are_bound_to_the_current_user_email_and_purpose(): void
    {
        Mail::fake();
        $owner = User::factory()->unverified()->create(['email' => 'owner@example.test']);
        $other = User::factory()->create(['email' => 'other@example.test']);

        $this->sendCode($owner);
        $passwordResetCode = $this->latestCode();
        $this->resetWithOtp($other->email, $passwordResetCode)->assertUnprocessable();
        $owner->update(['email' => 'changed@example.test']);
        $this->resetWithOtp($owner->fresh()->email, $passwordResetCode)->assertUnprocessable();

        $registrationOtp = AccountSecurityOtp::create([
            'user_id' => $owner->id,
            'email' => $owner->fresh()->email,
            'purpose' => AccountSecurityOtpPurpose::Registration->value,
            'code_hash' => Hash::make('111111'),
            'expires_at' => now()->addMinutes(10),
        ]);
        $this->resetWithOtp($owner->fresh()->email, '111111')->assertUnprocessable();

        $this->withToken($owner->createToken('test-token')->plainTextToken)
            ->postJson('/api/auth/email/otp/verify', ['code' => $passwordResetCode])
            ->assertUnprocessable();
        $this->assertNull($registrationOtp->fresh()->consumed_at);
    }

    public function test_malformed_or_invalid_password_submissions_do_not_consume_a_valid_code(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        $this->sendCode($user);
        $code = $this->latestCode();
        $this->postJson('/api/auth/password/otp/reset', [
            'email' => $user->email,
            'code' => 'abc',
            'password' => 'short',
            'password_confirmation' => 'different',
        ])->assertUnprocessable()->assertJsonValidationErrors(['code', 'password']);
        $this->resetWithOtp($user->email, $code, 'short', 'different')->assertUnprocessable()->assertJsonValidationErrors('password');

        $otp = AccountSecurityOtp::firstOrFail()->fresh();
        $this->assertNull($otp->consumed_at);
        $this->assertSame(0, $otp->attempt_count);
        $this->resetWithOtp($user->email, $code, 'new-secure-password')->assertOk();
    }

    public function test_existing_broker_reset_flow_remains_available(): void
    {
        $user = User::factory()->create(['email' => 'broker@example.test', 'password' => 'password']);
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-secure-password', $user->fresh()->password));
    }

    private function sendCode(User $user): void
    {
        $this->postJson('/api/auth/password/otp/send', ['email' => $user->email])->assertOk();
    }

    private function latestCode(): string
    {
        /** @var \Illuminate\Support\Collection<int, PasswordResetOtpMail> $mailables */
        $mailables = Mail::sent(PasswordResetOtpMail::class);

        return $mailables->last()->code;
    }

    private function resetWithOtp(string $email, string $code, string $password = 'new-secure-password', ?string $passwordConfirmation = null): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/auth/password/otp/reset', [
            'email' => $email,
            'code' => $code,
            'password' => $password,
            'password_confirmation' => $passwordConfirmation ?? $password,
        ]);
    }
}
