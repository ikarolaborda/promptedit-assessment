<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SocialAuthController;
use App\Http\Controllers\WebhookController;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Inertia\Inertia;

Route::get('/', fn () => redirect(auth()->check() ? '/studio' : '/login'));
Route::get('/privacy', fn () => Inertia::render('Privacy'));
Route::get('/auth/google/callback', [SocialAuthController::class, 'callback'])->defaults('provider', 'google')->middleware('throttle:oauth');
Route::post('/auth/apple/callback', [SocialAuthController::class, 'callback'])->defaults('provider', 'apple')->middleware('throttle:oauth')->withoutMiddleware(PreventRequestForgery::class);
Route::middleware('guest')->group(function () {
    Route::get('/auth/{provider}/redirect', [SocialAuthController::class, 'redirect'])->whereIn('provider', ['google', 'apple'])->middleware('throttle:oauth');
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
    Route::post('/demo', [AuthController::class, 'demo'])->middleware('throttle:demo');
});
Route::middleware('auth')->group(function () {
    Route::get('/account', [SocialAuthController::class, 'account']);
    Route::post('/account/connect/{provider}', [SocialAuthController::class, 'connect'])->whereIn('provider', ['google', 'apple'])->middleware('throttle:oauth');
    Route::get('/studio', [GenerationController::class, 'index'])->name('studio');
    Route::post('/quote', [GenerationController::class, 'quote'])->middleware('throttle:quote');
    Route::post('/generations', [GenerationController::class, 'store'])->middleware('throttle:generate');
    Route::get('/report', ReportController::class)->middleware('throttle:report');
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/credits', CreditController::class)->middleware('throttle:credits');
});
Route::post('/webhooks/pixelforge', WebhookController::class)->withoutMiddleware([
    StartSession::class,
    ShareErrorsFromSession::class,
    PreventRequestForgery::class,
    HandleInertiaRequests::class,
]);
