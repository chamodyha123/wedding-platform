<?php

namespace Tests\Feature;

use App\AccountSecurityOtpPurpose;
use App\Mail\PasswordChangeOtpMail;
use App\Models\AccountSecurityOtp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class PasswordChangeOtpTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_receive_a_hashed_password_change_code_without_verifying_email(): void
    {
        Mail::fake();
        $user = User::factory()->unverified()->create(['password' => 'password']);
        $token = $user->createToken('current')->plainTextToken;

        $this->postJson('/api/auth/password/change/otp/send')->assertUnauthorized();
        $this->withToken($token)->postJson('/api/auth/password/change/otp/send', ['current_password' => 'password'])
            ->assertOk()->assertJsonMissingPath('code')->assertJsonMissingPath('password');

        Mail::assertSent(PasswordChangeOtpMail::class, fn (PasswordChangeOtpMail $mail) => $mail->hasTo($user->email));
        $otp = AccountSecurityOtp::firstOrFail();
        $code = $this->latestCode();
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertTrue(Hash::check($code, $otp->code_hash));
        $this->assertNotSame($code, $otp->code_hash);
        $this->assertSame(AccountSecurityOtpPurpose::PasswordChange->value, $otp->purpose);
        $this->assertSame($user->id, $otp->user_id);
        $this->assertSame($user->email, $otp->email);
        $this->assertSame(0, $otp->attempt_count);
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_wrong_current_password_sends_no_code_and_creates_no_otp(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        $this->withToken($user->createToken('current')->plainTextToken)
            ->postJson('/api/auth/password/change/otp/send', ['current_password' => 'wrong-password'])
            ->assertUnprocessable();

        Mail::assertNothingSent();
        $this->assertDatabaseCount('account_security_otps', 0);
    }

    public function test_cooldown_replacement_and_other_purposes_are_isolated(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $token = $user->createToken('current')->plainTextToken;
        $registration = $this->otp($user, AccountSecurityOtpPurpose::Registration, '111111');
        $reset = $this->otp($user, AccountSecurityOtpPurpose::PasswordReset, '222222');

        $this->withToken($token)->postJson('/api/auth/password/change/otp/send', ['current_password' => 'password'])->assertOk();
        $change = AccountSecurityOtp::where('purpose', AccountSecurityOtpPurpose::PasswordChange->value)->firstOrFail();
        $oldCode = $this->latestCode();
        $this->withToken($token)->postJson('/api/auth/password/change/otp/send', ['current_password' => 'password'])->assertUnprocessable();
        $change->forceFill(['created_at' => now()->subSeconds(61)])->save();
        $this->withToken($token)->postJson('/api/auth/password/change/otp/send', ['current_password' => 'password'])->assertOk();

        $this->assertNotNull($change->fresh()->invalidated_at);
        $this->assertNull($registration->fresh()->invalidated_at);
        $this->assertNull($reset->fresh()->invalidated_at);
        $this->change($token, 'password', $oldCode)->assertUnprocessable();
    }

    public function test_password_change_requires_current_password_and_code_and_preserves_current_token(): void
    {
        Mail::fake();
        $user = User::factory()->create(['password' => 'password']);
        $current = $user->createToken('current')->plainTextToken;
        $other = $user->createToken('other')->plainTextToken;
        $this->withToken($current)->putJson('/api/auth/password', [])->assertUnprocessable()->assertJsonValidationErrors(['current_password', 'code', 'password']);
        $this->issue($current);
        $code = $this->latestCode();

        $this->change($current, 'wrong-password', $code)->assertUnprocessable();
        $this->change($current, 'password', $code, 'short', 'different')->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertNull(AccountSecurityOtp::firstOrFail()->fresh()->consumed_at);
        $this->change($current, 'password', $code)->assertOk();

        $this->assertTrue(Hash::check('new-secure-password', $user->fresh()->password));
        $this->assertNotNull(PersonalAccessToken::findToken($current));
        $this->assertNull(PersonalAccessToken::findToken($other));
        $this->assertNotNull(AccountSecurityOtp::firstOrFail()->fresh()->consumed_at);
        $this->change($current, 'new-secure-password', $code, 'another-secure-password')->assertUnprocessable();
    }

    public function test_wrong_codes_exhaust_and_other_purposes_cannot_change_password(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $token = $user->createToken('current')->plainTextToken;
        $registration = $this->otp($user, AccountSecurityOtpPurpose::Registration, '111111');
        $reset = $this->otp($user, AccountSecurityOtpPurpose::PasswordReset, '222222');
        $this->change($token, 'password', '111111')->assertUnprocessable();
        $this->change($token, 'password', '222222')->assertUnprocessable();
        $this->issue($token);
        $code = $this->latestCode();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->change($token, 'password', '000000')->assertUnprocessable();
        }

        $otp = AccountSecurityOtp::where('purpose', AccountSecurityOtpPurpose::PasswordChange->value)->firstOrFail()->fresh();
        $this->assertSame(5, $otp->attempt_count);
        $this->assertNotNull($otp->invalidated_at);
        $this->assertNull($registration->fresh()->invalidated_at);
        $this->assertNull($reset->fresh()->invalidated_at);
        $this->change($token, 'password', $code)->assertUnprocessable();
    }

    private function issue(string $token): void
    {
        $this->withToken($token)->postJson('/api/auth/password/change/otp/send', ['current_password' => 'password'])->assertOk();
    }

    private function change(string $token, string $currentPassword, string $code, string $password = 'new-secure-password', ?string $confirmation = null): \Illuminate\Testing\TestResponse
    {
        return $this->withToken($token)->putJson('/api/auth/password', ['current_password' => $currentPassword, 'code' => $code, 'password' => $password, 'password_confirmation' => $confirmation ?? $password]);
    }

    private function latestCode(): string
    {
        return Mail::sent(PasswordChangeOtpMail::class)->last()->code;
    }

    private function otp(User $user, AccountSecurityOtpPurpose $purpose, string $code): AccountSecurityOtp
    {
        return AccountSecurityOtp::create(['user_id' => $user->id, 'email' => $user->email, 'purpose' => $purpose->value, 'code_hash' => Hash::make($code), 'expires_at' => now()->addMinutes(10)]);
    }
}
