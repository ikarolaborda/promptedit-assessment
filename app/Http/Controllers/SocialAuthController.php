<?php

namespace App\Http\Controllers;

use App\Models\SocialIdentity;
use App\Services\SocialLogin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\Cookie;
use Throwable;

class SocialAuthController extends Controller
{
    public function account(Request $request): Response
    {
        return Inertia::render('Account', [
            'providers' => SocialLogin::available(),
            'connected' => SocialIdentity::where('user_id', $request->user()->id)->pluck('provider'),
            'canConnect' => ! $request->user()->is_admin,
            'csrfToken' => csrf_token(),
        ]);
    }

    public function redirect(Request $request, string $provider): RedirectResponse
    {
        return $this->start($request, $provider, null);
    }

    public function connect(Request $request, string $provider): RedirectResponse
    {
        abort_if($request->user()->is_admin, 403);

        return $this->start($request, $provider, $request->user()->id);
    }

    private function start(Request $request, string $provider, ?int $linkUserId): RedirectResponse
    {
        abort_unless(in_array($provider, SocialLogin::available(), true), 404);
        $driver = Socialite::driver($provider);
        if ($provider === 'apple') {
            $driver->stateless()->cookieNonce()->with(['state' => Str::random(40)]);
        }
        $response = $driver->redirect();
        parse_str(parse_url($response->getTargetUrl(), PHP_URL_QUERY), $query);
        $state = $query['state'];
        $request->session()->put('studio_oauth_state', $state);
        $binding = Str::random(64);
        Cache::put('oauth:'.hash('sha256', $state), [
            'provider' => $provider, 'binding' => hash('sha256', $binding), 'user_id' => $linkUserId,
        ], now()->addMinutes(10));

        return $response->withCookie(Cookie::create('studio_oauth_binding', $binding)
            ->withExpires(now()->addMinutes(10))->withSecure(true)->withHttpOnly(true)->withSameSite('none'));
    }

    public function callback(Request $request, string $provider, SocialLogin $login): RedirectResponse
    {
        abort_unless(in_array($provider, SocialLogin::available(), true), 404);
        $destination = '/login';
        try {
            $state = $request->input('state');
            $binding = $request->cookie('studio_oauth_binding');
            if (! is_string($state) || strlen($state) !== 40 || ! is_string($binding)) {
                throw new InvalidStateException;
            }
            $key = 'oauth:'.hash('sha256', $state);
            $attempt = Cache::lock($key.':lock', 10)->block(3, function () use ($key, $provider, $binding): array {
                $attempt = Cache::get($key);
                if (! is_array($attempt) || $attempt['provider'] !== $provider || ! hash_equals($attempt['binding'], hash('sha256', $binding))) {
                    throw new InvalidStateException;
                }
                Cache::forget($key);

                return $attempt;
            });
            $destination = $attempt['user_id'] ? '/account' : '/login';
            if ($request->filled('error')) {
                throw ValidationException::withMessages(['oauth' => 'Sign-in was cancelled. You can try again or use your email and password.']);
            }
            if (! is_string($request->input('code')) || ! $request->filled('code')) {
                throw new InvalidStateException;
            }
            $driver = Socialite::driver($provider);
            if ($provider === 'apple') {
                $driver->stateless()->cookieNonce();
                $request->merge(['user' => $this->appleName($request->input('user'))]);
            }
            $user = $login->resolve($provider, $driver->user(), $attempt['user_id']);
            Auth::login($user);
            $request->session()->regenerate();

            return $this->finish(redirect($attempt['user_id'] ? '/account' : '/studio')->with('message', $attempt['user_id'] ? ucfirst($provider).' sign-in connected.' : 'Welcome to your workspace.'));
        } catch (ValidationException $exception) {
            return $this->finish(redirect($destination)->withErrors($exception->errors()));
        } catch (InvalidStateException $exception) {
            return $this->finish(redirect('/login')->withErrors(['oauth' => 'This sign-in attempt expired or could not be verified. Please start again.']));
        } catch (Throwable $exception) {
            Log::warning('Social sign-in failed', ['provider' => $provider, 'exception' => $exception::class]);

            return $this->finish(redirect($destination)->withErrors(['oauth' => 'The sign-in provider could not complete this request. Please try again.']));
        }
    }

    /** @return array{name?: array{firstName: string, lastName: string}} */
    private function appleName(mixed $value): array
    {
        $data = is_string($value) ? json_decode($value, true) : $value;
        if (! is_array($data) || ! is_array($data['name'] ?? null)) {
            return [];
        }

        return ['name' => [
            'firstName' => is_string($data['name']['firstName'] ?? null) ? Str::limit($data['name']['firstName'], 100, '') : '',
            'lastName' => is_string($data['name']['lastName'] ?? null) ? Str::limit($data['name']['lastName'], 100, '') : '',
        ]];
    }

    private function finish(RedirectResponse $response): RedirectResponse
    {
        return $response->withoutCookie('studio_oauth_binding')->withoutCookie('socialite_apple_nonce');
    }
}
