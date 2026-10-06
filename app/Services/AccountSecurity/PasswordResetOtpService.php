<?php

namespace App\Services\AccountSecurity;

use App\AccountSecurityOtpPurpose;
use App\Mail\PasswordResetOtpMail;
use App\Models\AccountSecurityOtp;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class PasswordResetOtpService
{
    public function issue(string $email): void
    {
        $normalizedEmail = $this->normalizeEmail($email);
        $code = str_pad((string) random_int(0, 999999), config('account_security.password_reset_otp.length'), '0', STR_PAD_LEFT);

        $user = DB::transaction(function () use ($normalizedEmail, $code): ?User {
            $user = User::query()
                ->whereRaw('LOWER(email) = ?', [$normalizedEmail])
                ->lockForUpdate()
                ->first();

            if (! $user) {
                return null;
            }

            $otp = $user->accountSecurityOtps()
                ->where('purpose', AccountSecurityOtpPurpose::PasswordReset->value)
                ->whereNull('consumed_at')
                ->whereNull('invalidated_at')
                ->latest()
                ->lockForUpdate()
                ->first();

            if ($otp && $otp->created_at->gt(now()->subSeconds(config('account_security.password_reset_otp.resend_cooldown_seconds')))) {
                return null;
            }

            if ($otp) {
                $otp->update(['invalidated_at' => now()]);
            }

            AccountSecurityOtp::create([
                'user_id' => $user->id,
                'email' => $normalizedEmail,
                'purpose' => AccountSecurityOtpPurpose::PasswordReset->value,
                'code_hash' => Hash::make($code),
                'expires_at' => now()->addMinutes(config('account_security.password_reset_otp.expiry_minutes')),
            ]);

            return $user;
        });

        if ($user) {
            Mail::to($user->email)->send(new PasswordResetOtpMail($code));
        }
    }

    public function reset(string $email, string $code, string $password): bool
    {
        $normalizedEmail = $this->normalizeEmail($email);

        return DB::transaction(function () use ($normalizedEmail, $code, $password): bool {
            $user = User::query()
                ->whereRaw('LOWER(email) = ?', [$normalizedEmail])
                ->lockForUpdate()
                ->first();

            if (! $user) {
                return false;
            }

            $otp = $user->accountSecurityOtps()
                ->where('purpose', AccountSecurityOtpPurpose::PasswordReset->value)
                ->where('email', $normalizedEmail)
                ->whereNull('consumed_at')
                ->whereNull('invalidated_at')
                ->latest()
                ->lockForUpdate()
                ->first();

            if (! $otp || $otp->expires_at->isPast()) {
                return false;
            }

            if (! Hash::check($code, $otp->code_hash)) {
                $attempts = $otp->attempt_count + 1;
                $otp->update([
                    'attempt_count' => $attempts,
                    'invalidated_at' => $attempts >= config('account_security.password_reset_otp.max_attempts') ? now() : null,
                ]);

                return false;
            }

            $otp->update(['consumed_at' => now()]);
            $user->forceFill([
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
            ])->save();
            $user->tokens()->delete();
            Password::broker()->deleteToken($user);
            $user->accountSecurityOtps()
                ->where('purpose', AccountSecurityOtpPurpose::PasswordReset->value)
                ->whereNull('consumed_at')
                ->whereNull('invalidated_at')
                ->update(['invalidated_at' => now()]);

            return true;
        });
    }

    private function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }
}
