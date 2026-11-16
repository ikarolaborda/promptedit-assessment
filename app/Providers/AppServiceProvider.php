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
            RateLimiter::for($name, function (Request $request) use ($name, $maximum): array {
                $limits = [Limit::perMinute($maximum)->by($name.':actor:'.($request->user()?->id ?? $request->ip()))];
                if ($name === 'login' && is_string($request->input('email'))) {
                    $email = mb_strtolower(trim($request->input('email')));
                    $limits[] = Limit::perHour(20)->by('login:email:'.hash('sha256', $email));
                }
                if (in_array($name, ['register', 'generate', 'quote'], true)) {
                    $limits[] = Limit::perMinute(['register' => 20, 'generate' => 30, 'quote' => 300][$name])->by($name.':global:minute');
                }
                if (in_array($name, ['register', 'generate'], true)) {
                    $limits[] = Limit::perDay($name === 'register' ? 100 : 150)->by($name.':global:day');
                }

                return $limits;
            });
        }
    }
}
