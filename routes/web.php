<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\WebhookController;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::get('/', fn () => redirect(auth()->check() ? '/studio' : '/login'));
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
    Route::post('/demo', [AuthController::class, 'demo'])->middleware('throttle:demo');
});
Route::middleware('auth')->group(function () {
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
