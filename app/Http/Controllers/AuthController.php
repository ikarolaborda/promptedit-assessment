<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\SocialLogin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AuthController extends Controller
{
    public function show()
    {
        return Inertia::render('Auth', ['demoEnabled' => config('services.pixelforge.demo_enabled'), 'providers' => SocialLogin::available(),
            'registrationEnabled' => config('services.auth.password_registration_enabled')]);
    }

    public function login(Request $request)
    {
        $input = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (! Auth::attempt($input)) {
            throw ValidationException::withMessages(['email' => 'The email or password is incorrect.']);
        }
        $request->session()->regenerate();

        return redirect()->intended('/studio');
    }

    public function register(Request $request)
    {
        abort_unless(config('services.auth.password_registration_enabled'), 404);
        $input = $request->validate([
            'name' => 'required|string|max:100', 'email' => 'required|email|max:255|unique:users',
            'password' => ['required', 'confirmed', Password::min(12)],
        ]);
        Auth::login(User::create($input));
        $request->session()->regenerate();

        return redirect('/studio');
    }

    public function demo(Request $request)
    {
        abort_unless(config('services.pixelforge.demo_enabled'), 404);
        Auth::login(User::where('email', 'reviewer@example.test')->firstOrFail());
        $request->session()->regenerate();

        return redirect('/studio');
    }

    public function logout(Request $request)
    {
        $state = $request->session()->get('studio_oauth_state');
        if (is_string($state)) {
            Cache::forget('oauth:'.hash('sha256', $state));
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login')->withoutCookie('studio_oauth_binding')->withoutCookie('socialite_apple_nonce');
    }
}
