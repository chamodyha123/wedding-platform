<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserSocialAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;

class GoogleAuthController extends Controller
{
    protected const Provider = 'google';

    /**
     * Redirect a visitor to Google's state-protected authorization flow.
     */
    public function redirect(): RedirectResponse
    {
        return $this->socialDriver()->redirect();
    }

    /**
     * Start an authenticated request to link a Google identity.
     */
    public function link(Request $request): RedirectResponse
    {
        $request->session()->put(
            $this->linkSessionKey(),
            $request->user()->getAuthIdentifier()
        );

        return $this->socialDriver()->redirect();
    }

    /**
     * Resolve a validated Google identity to a local account.
     */
    public function callback(Request $request): JsonResponse
    {
        if ($request->filled('error') || ! $request->filled('code')) {
            return response()->json([
                'message' => $this->providerLabel().' authentication was not completed.',
            ], 422);
        }

        try {
            $socialUser = $this->socialDriver()->user();
        } catch (InvalidStateException) {
            return response()->json([
                'message' => $this->providerLabel().' authentication state is invalid or expired.',
            ], 403);
        } catch (\Throwable) {
            return response()->json([
                'message' => $this->providerLabel().' authentication could not be completed.',
            ], 422);
        }

        $providerUserId = trim((string) $socialUser->getId());

        if ($providerUserId === '') {
            return response()->json([
                'message' => $this->providerLabel().' did not provide a valid identity.',
            ], 422);
        }

        $linkingUserId = $request->session()->pull($this->linkSessionKey());

        if ($linkingUserId !== null) {
            return $this->linkGoogleIdentity((int) $linkingUserId, $providerUserId);
        }

        $socialAccount = UserSocialAccount::query()
            ->with('user.roles')
            ->where('provider', static::Provider)
            ->where('provider_user_id', $providerUserId)
            ->first();

        if ($socialAccount !== null) {
            return $this->authenticationResponse($socialAccount->user);
        }

        $email = $socialUser->getEmail();

        if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json([
                'message' => $this->providerLabel().' did not provide an email required for account creation.',
            ], 422);
        }

        if (User::query()->where('email', $email)->exists()) {
            return response()->json([
                'message' => 'Sign in to your existing account before linking '.$this->providerLabel().'.',
            ], 409);
        }

        $emailVerified = $this->emailIsVerified($socialUser->getRaw());

        try {
            $user = DB::transaction(function () use (
                $email,
                $emailVerified,
                $socialUser,
                $providerUserId
            ): User {
                if (User::query()->where('email', $email)->lockForUpdate()->exists()) {
                    throw new \RuntimeException('A local account already uses this email.');
                }

                $user = User::create([
                    'name' => $this->nameFor($socialUser->getName(), $email),
                    'email' => $email,
                    'password' => Hash::make(bin2hex(random_bytes(32))),
                ]);

                if ($emailVerified) {
                    $user->forceFill([
                        'email_verified_at' => now(),
                    ])->save();
                }

                $user->assignRole('customer');

                UserSocialAccount::create([
                    'user_id' => $user->id,
                    'provider' => static::Provider,
                    'provider_user_id' => $providerUserId,
                ]);

                return $user->load('roles');
            });
        } catch (\Throwable) {
            return response()->json([
                'message' => $this->providerLabel().' authentication could not be completed.',
            ], 409);
        }

        return $this->authenticationResponse($user, 201);
    }

    /**
     * Configure Google with only the identity scopes required by this application.
     */
    protected function socialDriver(): Provider
    {
        return Socialite::driver(static::Provider)->setScopes([
            'openid',
            'profile',
            'email',
        ]);
    }

    /**
     * Link a validated identity to the user that initiated this browser session.
     */
    protected function linkGoogleIdentity(int $userId, string $providerUserId): JsonResponse
    {
        $existingIdentity = UserSocialAccount::query()
            ->where('provider', static::Provider)
            ->where('provider_user_id', $providerUserId)
            ->first();

        if ($existingIdentity !== null) {
            return response()->json([
                'message' => 'This '.$this->providerLabel().' identity is already linked to an account.',
            ], 409);
        }

        $user = User::query()->find($userId);

        if ($user === null) {
            return response()->json([
                'message' => $this->providerLabel().' linking could not be completed.',
            ], 422);
        }

        if ($user->socialAccounts()->where('provider', static::Provider)->exists()) {
            return response()->json([
                'message' => 'Your account already has a linked '.$this->providerLabel().' identity.',
            ], 409);
        }

        try {
            $user->socialAccounts()->create([
                'provider' => static::Provider,
                'provider_user_id' => $providerUserId,
            ]);
        } catch (\Throwable) {
            return response()->json([
                'message' => $this->providerLabel().' linking could not be completed.',
            ], 409);
        }

        return response()->json([
            'message' => $this->providerLabel().' identity linked successfully.',
        ]);
    }

    /**
     * Issue the standard application token without exposing provider credentials.
     */
    protected function authenticationResponse(User $user, int $status = 200): JsonResponse
    {
        return response()->json([
            'message' => $this->providerLabel().' authentication successful.',
            'user' => $user->load('roles'),
            'token' => $user->createToken('api-token')->plainTextToken,
        ], $status);
    }

    /**
     * Use Google's display name only as an initial local name.
     */
    protected function nameFor(?string $name, string $email): string
    {
        return filled($name) ? mb_substr($name, 0, 255) : Str::before($email, '@');
    }

    /**
     * Determine whether the provider explicitly verified the returned email.
     *
     * @param  array<string, mixed>  $identity
     */
    protected function emailIsVerified(array $identity): bool
    {
        return ($identity['email_verified'] ?? false) === true;
    }

    /**
     * Distinguish linking intent between providers in the session.
     */
    protected function linkSessionKey(): string
    {
        return static::Provider.'_link_user_id';
    }

    /**
     * Return the provider name for controlled user-facing responses.
     */
    protected function providerLabel(): string
    {
        return 'Google';
    }
}
