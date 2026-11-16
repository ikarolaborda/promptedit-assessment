<?php

namespace Tests\Feature;

use App\Models\SocialIdentity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as ProviderUser;
use Tests\TestCase;

class SocialAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::flush();
        config([
            'services.google.client_id' => 'google-client',
            'services.google.client_secret' => 'test-secret',
            'services.google.redirect' => 'https://studio.example.test/auth/google/callback',
            'services.apple.client_id' => 'apple-client',
            'services.apple.team_id' => 'TEAMID',
            'services.apple.key_id' => 'KEYID',
            'services.apple.private_key' => 'test-private-key',
            'services.apple.redirect' => 'https://studio.example.test/auth/apple/callback',
        ]);
    }

    private function attempt(string $provider = 'google', ?int $userId = null): array
    {
        $state = str_repeat('a', 40);
        $binding = str_repeat('b', 64);
        Cache::put('oauth:'.hash('sha256', $state), ['provider' => $provider, 'binding' => hash('sha256', $binding), 'user_id' => $userId], 600);
        $this->withCookie('studio_oauth_binding', $binding);

        return ['state' => $state, 'code' => 'single-use-test-code'];
    }

    private function identity(array $extra = []): ProviderUser
    {
        return ProviderUser::fake($extra + ['id' => 'provider-subject', 'email' => 'member@example.test', 'email_verified' => true]);
    }

    public function test_only_configured_providers_are_shown(): void
    {
        config(['services.apple.private_key' => '']);
        $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('providers', ['google']));
        $this->get('/auth/apple/redirect')->assertNotFound();
    }

    public function test_google_redirect_has_state_and_a_secure_browser_binding(): void
    {
        $response = $this->get('/auth/google/redirect')->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('google-client', $query['client_id']);
        $this->assertSame(40, strlen($query['state']));
        $this->assertStringContainsString('openid', $query['scope']);
        $this->assertTrue(Cache::has('oauth:'.hash('sha256', $query['state'])));
        $cookie = $response->getCookie('studio_oauth_binding');
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('none', $cookie->getSameSite());
        $this->assertSame('lax', config('session.same_site'));
    }

    public function test_apple_redirect_uses_nonce_cookie_and_form_post(): void
    {
        $response = $this->get('/auth/apple/redirect')->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('form_post', $query['response_mode']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame(40, strlen($query['nonce']));
        $this->assertSame(40, strlen($query['state']));
        $this->assertTrue($response->getCookie('socialite_apple_nonce')->isSecure());
        $this->assertTrue($response->getCookie('socialite_apple_nonce')->isHttpOnly());
    }

    public function test_verified_google_identity_creates_one_private_non_admin_workspace(): void
    {
        Socialite::fake('google', $this->identity());
        $this->get('/auth/google/callback?'.http_build_query($this->attempt()))->assertRedirect('/studio');
        $this->assertAuthenticated();
        $user = Auth::user()->fresh();
        $this->assertFalse($user->is_admin);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame(5000000000000, $user->balance_pico);
        $this->assertDatabaseCount('social_identities', 1);
        $this->assertDatabaseCount('generations', 0);
        $this->assertArrayNotHasKey('token', $user->getAttributes());
    }

    public function test_verified_apple_identity_can_sign_in_from_cross_site_post(): void
    {
        Socialite::fake('apple', $this->identity(['email' => 'private@privaterelay.appleid.com', 'email_verified' => 'true']));
        $this->post('/auth/apple/callback', $this->attempt('apple'))->assertRedirect('/studio');
        $this->assertAuthenticated();
        $this->assertDatabaseHas('social_identities', ['provider' => 'apple']);
    }

    public function test_missing_apple_nonce_is_rejected_before_code_exchange(): void
    {
        $this->post('/auth/apple/callback', $this->attempt('apple'))->assertRedirect('/login')->assertSessionHasErrors('oauth');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_missing_google_session_state_is_rejected_before_code_exchange(): void
    {
        $this->get('/auth/google/callback?'.http_build_query($this->attempt()))->assertRedirect('/login')->assertSessionHasErrors('oauth');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_existing_subject_logs_into_the_same_user_even_if_email_changes(): void
    {
        $user = User::factory()->create();
        SocialIdentity::create(['user_id' => $user->id, 'provider' => 'google', 'subject' => 'provider-subject']);
        Socialite::fake('google', $this->identity(['email' => 'changed@example.test']));
        $this->get('/auth/google/callback?'.http_build_query($this->attempt()))->assertRedirect('/studio');
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('social_identities', 1);
    }

    public function test_matching_email_does_not_automatically_link_or_take_over_an_account(): void
    {
        $user = User::factory()->create(['email' => 'member@example.test']);
        Socialite::fake('google', $this->identity());
        $this->get('/auth/google/callback?'.http_build_query($this->attempt()))->assertRedirect('/login')->assertSessionHasErrors('oauth');
        $this->assertGuest();
        $this->assertDatabaseCount('social_identities', 0);
        $this->assertSame($user->password, $user->fresh()->password);
    }

    public function test_unverified_email_cannot_create_an_account(): void
    {
        Socialite::fake('google', $this->identity(['email_verified' => false]));
        $this->get('/auth/google/callback?'.http_build_query($this->attempt()))->assertSessionHasErrors('oauth');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_callback_from_another_browser_cannot_consume_the_attempt(): void
    {
        Socialite::fake('google', fn () => $this->fail('Provider must not be called for an invalid browser binding.'));
        $query = $this->attempt();
        $this->withCookie('studio_oauth_binding', 'wrong-browser');
        $this->get('/auth/google/callback?'.http_build_query($query))->assertSessionHasErrors('oauth');
        $this->assertTrue(Cache::has('oauth:'.hash('sha256', $query['state'])));
        $this->assertGuest();
    }

    public function test_expired_state_is_rejected(): void
    {
        Socialite::fake('google', fn () => $this->fail('Provider must not be called for expired state.'));
        $query = $this->attempt();
        $this->travel(11)->minutes();
        $this->get('/auth/google/callback?'.http_build_query($query))->assertSessionHasErrors('oauth');
        $this->assertGuest();
    }

    public function test_callback_replay_cannot_create_a_second_session_or_credit_grant(): void
    {
        Socialite::fake('google', $this->identity());
        $query = $this->attempt();
        $this->get('/auth/google/callback?'.http_build_query($query))->assertRedirect('/studio');
        Auth::logout();
        $this->get('/auth/google/callback?'.http_build_query($query))->assertRedirect('/login')->assertSessionHasErrors('oauth');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('social_identities', 1);
    }

    public function test_cancelled_consent_returns_a_friendly_message(): void
    {
        $query = $this->attempt();
        $query['error'] = 'access_denied';
        $this->get('/auth/google/callback?'.http_build_query($query))->assertRedirect('/login')->assertSessionHasErrors('oauth');
        $this->assertGuest();
    }

    public function test_authenticated_user_can_explicitly_link_a_second_provider(): void
    {
        $user = User::factory()->create();
        Socialite::fake('apple', $this->identity());
        $this->post('/auth/apple/callback', $this->attempt('apple', $user->id))->assertRedirect('/account');
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('social_identities', ['user_id' => $user->id, 'provider' => 'apple']);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_identity_already_owned_by_another_user_cannot_be_linked(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        SocialIdentity::create(['user_id' => $owner->id, 'provider' => 'apple', 'subject' => 'provider-subject']);
        Socialite::fake('apple', $this->identity());
        $this->post('/auth/apple/callback', $this->attempt('apple', $other->id))->assertRedirect('/account')->assertSessionHasErrors('oauth');
        $this->assertDatabaseCount('social_identities', 1);
        $this->assertGuest();
    }

    public function test_shared_demo_cannot_link_a_personal_identity(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['is_admin' => true])->save();
        $this->actingAs($user)->post('/account/connect/google')->assertForbidden();
    }

    public function test_logout_cancels_a_pending_account_link(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->post('/account/connect/apple')->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $key = 'oauth:'.hash('sha256', $query['state']);
        $this->assertSame($user->id, Cache::get($key)['user_id']);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertFalse(Cache::has($key));
        $this->assertGuest();
    }

    public function test_account_requires_authentication_and_shows_only_its_own_connections(): void
    {
        $this->get('/account')->assertRedirect('/login');
        $user = User::factory()->create();
        $other = User::factory()->create();
        SocialIdentity::create(['user_id' => $other->id, 'provider' => 'google', 'subject' => 'other']);
        $this->actingAs($user)->get('/account')->assertInertia(fn (Assert $page) => $page->component('Account')->where('connected', []));
    }
}
