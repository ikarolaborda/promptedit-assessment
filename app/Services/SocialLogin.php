<?php

namespace App\Services;

use App\Models\SocialIdentity;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Contracts\User as ProviderUser;

class SocialLogin
{
    /** @return list<string> */
    public static function available(): array
    {
        return array_values(array_filter(['google', 'apple'], static function (string $provider): bool {
            return (bool) config("services.$provider.client_id") && ($provider === 'google'
                ? (bool) config('services.google.client_secret')
                : (bool) config('services.apple.private_key') && (bool) config('services.apple.key_id') && (bool) config('services.apple.team_id'));
        }));
    }

    public function resolve(string $provider, ProviderUser $remote, ?int $linkUserId = null): User
    {
        $subject = (string) $remote->getId();
        if ($subject === '' || strlen($subject) > 255) {
            throw ValidationException::withMessages(['oauth' => 'The sign-in provider did not return a valid identity.']);
        }
        try {
            return DB::transaction(function () use ($provider, $remote, $linkUserId, $subject): User {
                $identity = SocialIdentity::where('provider', $provider)->where('subject', $subject)->first();
                if ($identity) {
                    if ($linkUserId !== null && $identity->user_id !== $linkUserId) {
                        throw ValidationException::withMessages(['oauth' => 'This sign-in identity is already connected to another workspace.']);
                    }

                    return $identity->user;
                }
                if ($linkUserId !== null) {
                    $user = User::whereKey($linkUserId)->lockForUpdate()->firstOrFail();
                    if ($user->is_admin || SocialIdentity::where('user_id', $user->id)->where('provider', $provider)->exists()) {
                        throw ValidationException::withMessages(['oauth' => 'This workspace cannot connect another identity for this provider.']);
                    }
                } else {
                    $email = Str::lower((string) $remote->getEmail());
                    $raw = $remote->getRaw();
                    $verified = filter_var($raw['email_verified'] ?? $raw['verified_email'] ?? false, FILTER_VALIDATE_BOOLEAN);
                    if (! $verified || strlen($email) > 255 || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        throw ValidationException::withMessages(['oauth' => 'Please use a provider account with a verified email address.']);
                    }
                    if (User::whereRaw('LOWER(email) = ?', [$email])->exists()) {
                        throw ValidationException::withMessages(['oauth' => 'An account already uses this email. Sign in using its existing method, then connect this provider from Account.']);
                    }
                    $name = Str::limit(trim(strip_tags((string) $remote->getName())), 100, '') ?: 'Studio member';
                    $user = User::create(['name' => $name, 'email' => $email, 'password' => Str::random(64)]);
                    $user->forceFill(['email_verified_at' => now()])->save();
                }
                SocialIdentity::create(['user_id' => $user->id, 'provider' => $provider, 'subject' => $subject]);

                return $user;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $identity = SocialIdentity::where('provider', $provider)->where('subject', $subject)->first();
            if ($identity && ($linkUserId === null || $identity->user_id === $linkUserId)) {
                return $identity->user;
            }
            throw ValidationException::withMessages(['oauth' => 'This account is already registered or connected. Please sign in again using its existing method.']);
        }
    }
}
