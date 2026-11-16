<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Apple\Provider;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(function (SocialiteWasCalled $event): void {
            $event->extendSocialite('apple', Provider::class);
        });
        if ($this->app->environment('production') && str_starts_with(config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }
        foreach (['login' => 8, 'register' => 4, 'demo' => 10, 'quote' => 60, 'generate' => 6, 'report' => 10, 'credits' => 5, 'oauth' => 15] as $name => $maximum) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinute($maximum)->by($name.':'.($request->user()?->id ?? $request->ip())));
        }
    }
}
