<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Providers\AppServiceProvider;
use Tests\TestCase;

class RoutingTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_home_redirects_guests_to_login(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/login');
    }

    public function test_production_https_deployment_never_redirects_to_plain_http(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['app.url' => 'https://studio.example.test']);
        (new AppServiceProvider($this->app))->boot();
        $response = $this->get('/')->assertStatus(302);
        $this->assertSame('https', parse_url($response->headers->get('Location'), PHP_URL_SCHEME));
        $this->assertSame('/login', parse_url($response->headers->get('Location'), PHP_URL_PATH));
    }
}
