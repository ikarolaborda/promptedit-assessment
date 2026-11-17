<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Providers\AppServiceProvider;
use Illuminate\Http\Request;
use Tests\TestCase;

class RoutingTest extends TestCase
{
    protected function tearDown(): void
    {
        Request::setTrustedHosts([]);
        parent::tearDown();
    }

    /**
     * A basic test example.
     */
    public function test_home_redirects_guests_to_login(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/login');
    }

    public function test_password_registration_is_closed_without_email_verification(): void
    {
        config(['services.auth.password_registration_enabled' => false]);
        $this->post('/register', ['name' => 'Squatter', 'email' => 'other-person@example.test', 'password' => 'safe-test-password',
            'password_confirmation' => 'safe-test-password'])->assertNotFound();
    }

    public function test_production_https_deployment_never_redirects_to_plain_http(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['app.url' => 'https://studio.example.test']);
        (new AppServiceProvider($this->app))->boot();
        $response = $this->get('https://studio.example.test/')->assertStatus(302);
        $this->assertSame('https', parse_url($response->headers->get('Location'), PHP_URL_SCHEME));
        $this->assertSame('/login', parse_url($response->headers->get('Location'), PHP_URL_PATH));
    }

    public function test_production_rejects_other_hosts_but_allows_internal_health_probe(): void
    {
        $this->withoutVite();
        $this->app->detectEnvironment(fn () => 'production');
        config(['app.url' => 'https://studio.example.test']);

        $this->get('https://attacker.example/login')->assertBadRequest();
        $this->get('http://127.0.0.1/up')->assertOk();
        $this->get('https://studio.example.test/login')->assertOk();
    }
}
