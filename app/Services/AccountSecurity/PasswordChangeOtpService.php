<?php

namespace App\Services\AccountSecurity;

use App\AccountSecurityOtpPurpose;
use App\Mail\PasswordChangeOtpMail;
use App\Models\AccountSecurityOtp;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class PasswordChangeOtpService
{
    public function issue(User $user, string $currentPassword): bool
    {
        if (! Hash::check($currentPassword, $user->password)) {
            return false;
        }

        $email = $this->normalizeEmail($user->email);
        $code = str_pad((string) random_int(0, 999999), config('account_security.password_change_otp.length'), '0', STR_PAD_LEFT);

        $issued = DB::transaction(function () use ($user, $email, $code): bool {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $otp = $lockedUser->accountSecurityOtps()->where('purpose', AccountSecurityOtpPurpose::PasswordChange->value)->whereNull('consumed_at')->whereNull('invalidated_at')->latest()->lockForUpdate()->first();

            if ($otp && $otp->created_at->gt(now()->subSeconds(config('account_security.password_change_otp.resend_cooldown_seconds')))) {
                return false;
            }

            if ($otp) {
                $otp->update(['invalidated_at' => now()]);
            }

            AccountSecurityOtp::create([
                'user_id' => $lockedUser->id,
                'email' => $email,
                'purpose' => AccountSecurityOtpPurpose::PasswordChange->value,
                'code_hash' => Hash::make($code),
                'expires_at' => now()->addMinutes(config('account_security.password_change_otp.expiry_minutes')),
            ]);

            return true;
        });

        if ($issued) {
            Mail::to($user->email)->send(new PasswordChangeOtpMail($code));
        }

        return $issued;
    }

    public function change(User $user, ?int $currentAccessTokenId, string $currentPassword, string $code, string $password): bool
    {
        return DB::transaction(function () use ($user, $currentAccessTokenId, $currentPassword, $code, $password): bool {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            if (! Hash::check($currentPassword, $lockedUser->password)) {
                return false;
            }

            $email = $this->normalizeEmail($lockedUser->email);
            $otp = $lockedUser->accountSecurityOtps()->where('purpose', AccountSecurityOtpPurpose::PasswordChange->value)->where('email', $email)->whereNull('consumed_at')->whereNull('invalidated_at')->latest()->lockForUpdate()->first();

            if (! $otp || $otp->expires_at->isPast()) {
                return false;
            }

            if (! Hash::check($code, $otp->code_hash)) {
                $attempts = $otp->attempt_count + 1;
                $otp->update(['attempt_count' => $attempts, 'invalidated_at' => $attempts >= config('account_security.password_change_otp.max_attempts') ? now() : null]);

                return false;
            }

            $otp->update(['consumed_at' => now()]);
            $lockedUser->forceFill(['password' => Hash::make($password)])->save();
            $tokens = $lockedUser->tokens();

            if ($currentAccessTokenId) {
                $tokens->whereKeyNot($currentAccessTokenId);
            }

            $tokens->delete();
            $lockedUser->accountSecurityOtps()->where('purpose', AccountSecurityOtpPurpose::PasswordChange->value)->whereNull('consumed_at')->whereNull('invalidated_at')->update(['invalidated_at' => now()]);

            return true;
        });
    }

    private function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }
}
