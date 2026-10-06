<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AccountSecurity\PasswordChangeOtpService;
use App\Services\AccountSecurity\PasswordResetOtpService;
use App\Services\AccountSecurity\RegistrationOtpService;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AccountSecurityController extends Controller
{
    public function sendRegistrationOtp(Request $request, RegistrationOtpService $service): JsonResponse
    {
        $service->issue($request->user());

        return response()->json(['message' => 'If email verification is needed, a verification code has been sent.']);
    }

    public function verifyRegistrationOtp(Request $request, RegistrationOtpService $service): JsonResponse
    {
        $validated = $request->validate(['code' => ['required', 'digits:6']]);
        if (! $service->verify($request->user(), $validated['code'])) {
            return response()->json(['message' => 'The verification code is invalid or expired.'], 422);
        }

        return response()->json(['message' => 'Email verified successfully.']);
    }

    /**
     * Send a verification email to the authenticated user when needed.
     */
    public function sendVerificationNotification(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return response()->json([
            'message' => 'If email verification is needed, a verification link has been sent.',
        ]);
    }

    /**
     * Verify the email address associated with a valid, signed link.
     */
    public function verifyEmail(User $user, string $hash): JsonResponse
    {
        if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            abort(403);
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();

            event(new Verified($user));
        }

        return response()->json([
            'message' => 'Email verified successfully.',
        ]);
    }

    /**
     * Send a reset link without revealing whether the email is registered.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        Password::sendResetLink([
            'email' => $validated['email'],
        ]);

        return response()->json([
            'message' => 'If an account exists for this email, a password reset link has been sent.',
        ]);
    }

    /**
     * Reset a password through Laravel's password broker.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset($validated, function (User $user, string $password): void {
            $user->forceFill([
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
            ])->save();

            $user->tokens()->delete();
        });

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'Unable to reset the password with the provided credentials.',
            ], 422);
        }

        return response()->json([
            'message' => 'Password reset successfully. Please log in again.',
        ]);
    }

    public function sendPasswordResetOtp(Request $request, PasswordResetOtpService $service): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $service->issue($validated['email']);

        return response()->json([
            'message' => 'If an account exists for this email, a password reset code has been sent.',
        ]);
    }

    public function resetPasswordWithOtp(Request $request, PasswordResetOtpService $service): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'code' => ['required', 'digits:6'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        if (! $service->reset($validated['email'], $validated['code'], $validated['password'])) {
            return response()->json([
                'message' => 'Unable to reset the password with the provided credentials.',
            ], 422);
        }

        return response()->json([
            'message' => 'Password reset successfully. Please log in again.',
        ]);
    }

    /**
     * Change the authenticated user's password while preserving the current session.
     */
    public function sendPasswordChangeOtp(Request $request, PasswordChangeOtpService $service): JsonResponse
    {
        $validated = $request->validate(['current_password' => ['required', 'string']]);

        if (! $service->issue($request->user(), $validated['current_password'])) {
            return response()->json(['message' => 'Unable to send a password change code.'], 422);
        }

        return response()->json(['message' => 'A password change code has been sent.']);
    }

    public function changePassword(Request $request, PasswordChangeOtpService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'code' => ['required', 'digits:6'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        if (! $service->change($user, $user->currentAccessToken()?->getKey(), $validated['current_password'], $validated['code'], $validated['password'])) {
            return response()->json([
                'message' => 'Unable to change the password with the provided credentials.',
            ], 422);
        }

        return response()->json([
            'message' => 'Password changed successfully.',
        ]);
    }
}
