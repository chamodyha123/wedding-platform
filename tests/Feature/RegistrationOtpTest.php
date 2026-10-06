<?php

namespace Tests\Feature;

use App\Mail\RegistrationOtpMail;
use App\Models\AccountSecurityOtp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
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
}
