<?php

namespace App\Services\AccountSecurity;

use App\Mail\RegistrationOtpMail;
use App\Models\AccountSecurityOtp;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class RegistrationOtpService
{
    public function issue(User $user): void
    {
        if ($user->hasVerifiedEmail()) {
            return;
        }
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        DB::transaction(function () use ($user, $code): void {
            $otp = $user->accountSecurityOtps()->where('purpose', 'registration')->whereNull('consumed_at')->whereNull('invalidated_at')->latest()->lockForUpdate()->first();
            if ($otp && $otp->created_at->gt(now()->subSeconds(config('account_security.registration_otp.resend_cooldown_seconds')))) {
                throw ValidationException::withMessages(['code' => ['Please wait before requesting another code.']]);
            }
            if ($otp) {
                $otp->update(['invalidated_at' => now()]);
            }
            AccountSecurityOtp::create(['user_id' => $user->id, 'email' => mb_strtolower($user->email), 'purpose' => 'registration', 'code_hash' => Hash::make($code), 'expires_at' => now()->addMinutes(config('account_security.registration_otp.expiry_minutes'))]);
        });
        Mail::to($user->email)->send(new RegistrationOtpMail($code));
    }

    public function verify(User $user, string $code): bool
    {
        if ($user->hasVerifiedEmail()) {
            return false;
        }

        return DB::transaction(function () use ($user, $code): bool {
            $otp = $user->accountSecurityOtps()->where('purpose', 'registration')->where('email', mb_strtolower($user->email))->whereNull('consumed_at')->whereNull('invalidated_at')->latest()->lockForUpdate()->first();
            if (! $otp || $otp->expires_at->isPast()) {
                return false;
            }
            if (! Hash::check($code, $otp->code_hash)) {
                $attempts = $otp->attempt_count + 1;
                $otp->update(['attempt_count' => $attempts, 'invalidated_at' => $attempts >= config('account_security.registration_otp.max_attempts') ? now() : null]);

                return false;
            }
            $otp->update(['consumed_at' => now()]);
            $user->markEmailAsVerified();

            return true;
        });
    }
}
