<?php

namespace Tests\Feature;

use App\Mail\RegistrationOtpMail;
use App\Models\AccountSecurityOtp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RegistrationOtpTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_unverified_user_can_receive_and_verify_registration_otp(): void
    {
        Mail::fake();
        $user = User::factory()->unverified()->create();
        $token = $user->createToken('test')->plainTextToken;
        $this->postJson('/api/auth/email/otp/send')->assertUnauthorized();
        $this->withToken($token)->postJson('/api/auth/email/otp/send')->assertOk()->assertJsonMissingPath('code');
        Mail::assertSent(RegistrationOtpMail::class, function (RegistrationOtpMail $mail) use (&$code): bool {
            $code = $mail->code;

            return true;
        });
        $otp = AccountSecurityOtp::firstOrFail();
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertNotSame($code, $otp->code_hash);
        $this->withToken($token)->postJson('/api/auth/email/otp/verify', ['code' => $code])->assertOk();
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertNotNull($otp->fresh()->consumed_at);
    }

    public function test_wrong_codes_exhaust_after_five_attempts(): void
    {
        Mail::fake();
        $user = User::factory()->unverified()->create();
        $token = $user->createToken('test')->plainTextToken;
        $this->withToken($token)->postJson('/api/auth/email/otp/send');
        for ($i = 0; $i < 5; $i++) {
            $this->withToken($token)->postJson('/api/auth/email/otp/verify', ['code' => '000000'])->assertUnprocessable();
        }
        $this->assertNotNull(AccountSecurityOtp::firstOrFail()->fresh()->invalidated_at);
    }

    public function test_cooldown_replacement_replay_and_validation_are_enforced(): void
    {
        Mail::fake();
        $user = User::factory()->unverified()->create();
        $token = $user->createToken('test')->plainTextToken;
        $this->withToken($token)->postJson('/api/auth/email/otp/send')->assertOk();
        Mail::assertSent(RegistrationOtpMail::class, function (RegistrationOtpMail $mail) use (&$firstCode): bool { $firstCode = $mail->code; return true; });
        $first = AccountSecurityOtp::firstOrFail();
        $this->withToken($token)->postJson('/api/auth/email/otp/send')->assertUnprocessable();
        Mail::assertSent(RegistrationOtpMail::class, 1);
        $first->forceFill(['created_at' => now()->subSeconds(61)])->save();
        $this->withToken($token)->postJson('/api/auth/email/otp/send')->assertOk();
        $this->assertNotNull($first->fresh()->invalidated_at);
        $this->withToken($token)->postJson('/api/auth/email/otp/verify', ['code' => $firstCode])->assertUnprocessable();
        $this->withToken($token)->postJson('/api/auth/email/otp/verify', ['code' => 'abc'])->assertUnprocessable();
    }

    public function test_codes_are_bound_to_the_owner_and_current_email(): void
    {
        Mail::fake();
        $owner = User::factory()->unverified()->create();
        $other = User::factory()->unverified()->create();
        $ownerToken = $owner->createToken('test')->plainTextToken;
        $otherToken = $other->createToken('test')->plainTextToken;
        $this->flushHeaders()->withToken($ownerToken)->postJson('/api/auth/email/otp/send');
        Mail::assertSent(RegistrationOtpMail::class, function (RegistrationOtpMail $mail) use (&$code): bool { $code = $mail->code; return true; });
        Sanctum::actingAs($other);
        $this->postJson('/api/auth/email/otp/verify', ['code' => $code])->assertUnprocessable();
        $owner->update(['email' => 'changed@example.test']);
        $this->assertSame('changed@example.test', $owner->fresh()->email);
        $this->app['auth']->forgetGuards();
        $changedEmailToken = $owner->fresh()->createToken('changed-email')->plainTextToken;
        Sanctum::actingAs($owner->fresh());
        $this->postJson('/api/auth/email/otp/verify', ['code' => $code])->assertUnprocessable();
    }
}
