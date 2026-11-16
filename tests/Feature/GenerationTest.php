<?php

namespace Tests\Feature;

use App\Jobs\PollGeneration;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\Money;
use App\Services\Pixelforge;
use App\Services\Quote;
use App\Services\SettleGeneration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class GenerationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected array $pricing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::flush();
        Queue::fake();
        $this->pricing = json_decode(file_get_contents(__DIR__.'/../Fixtures/pricing.json'), true);
        config(['services.pixelforge.webhook_secret' => 'whsec_test_signing_secret', 'services.pixelforge.key' => 'test-key',
            'services.auth.password_registration_enabled' => true]);
        Http::preventStrayRequests();
        Http::fake(['*/pricing' => Http::response($this->pricing)]);
        $this->user = User::factory()->create();
    }

    private function input(array $extra = []): array
    {
        return $extra + ['prompt' => 'A quiet coast [succeed]', 'size' => '1024x1024', 'quality' => 'medium', 'reference_images' => [], 'client_request_id' => (string) Str::uuid()];
    }

    private function generation(array $extra = []): Generation
    {
        return Generation::create($extra + ['user_id' => $this->user->id, 'client_request_id' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64), 'prompt' => 'test', 'size' => '1536x1024', 'quality' => 'high', 'reference_images' => [], 'estimated_cost_pico' => 400000000000, 'minimum_cost_pico' => 300000000000, 'maximum_cost_pico' => 500000000000, 'reserved_pico' => 500000000000]);
    }

    private function remote(Generation $g, string $status = 'succeeded'): array
    {
        return ['id' => 'gen_'.$g->id, 'status' => $status, 'metadata' => ['app_generation_id' => $g->id], 'completed_at' => in_array($status, ['succeeded', 'failed']) ? '2026-11-16T10:00:00Z' : null, 'image_url' => $status === 'succeeded' ? 'https://example.com/image.png' : null, 'image_url_expires_at' => '2026-11-17T10:00:00Z', 'usage' => ['input_text_tokens' => 12, 'input_image_tokens' => 765, 'output_tokens' => 6450, 'reasoning_tokens' => 3184], 'rates' => $this->pricing['rates'], 'error' => $status === 'failed' ? ['code' => 'generation_failed'] : null];
    }

    private function webhook(array $event, ?int $time = null, string $secret = 'whsec_test_signing_secret')
    {
        $raw = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $time ??= time();

        return $this->call('POST', '/webhooks/pixelforge', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_PIXELFORGE_SIGNATURE' => 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$raw, $secret)], $raw);
    }

    private function event(Generation $g, string $state = 'succeeded', ?string $id = null): array
    {
        return ['id' => $id ?? 'evt_'.Str::uuid(), 'type' => 'generation.'.$state, 'data' => ['object' => $this->remote($g, $state)]];
    }

    public function test_guests_cannot_generate(): void
    {
        $this->post('/generations', $this->input())->assertRedirect('/login');
    }

    public function test_quote_comes_from_live_rates_and_respects_unicode(): void
    {
        $this->actingAs($this->user)->postJson('/quote', $this->input(['prompt' => 'é🌿']))->assertOk()->assertJsonStructure(['estimate', 'minimum', 'maximum']);
    }

    public function test_submissions_and_replays_create_only_one_job_and_reservation(): void
    {
        $input = $this->input();
        $this->actingAs($this->user)->post('/generations', $input)->assertRedirect('/studio');
        $this->post('/generations', $input)->assertRedirect('/studio');
        $this->assertDatabaseCount('generations', 1);
        Queue::assertPushed(SubmitGeneration::class, 1);
        $g = Generation::first();
        $this->assertSame($g->maximum_cost_pico, $g->reserved_pico);
        $this->assertSame(5000000000000, $this->user->fresh()->balance_pico);
        $input['prompt'] = 'different';
        $this->post('/generations', $input)->assertStatus(409);
    }

    public function test_invalid_size_quality_references_and_prompt_are_rejected(): void
    {
        $this->actingAs($this->user)->postJson('/generations', $this->input(['size' => 'bad', 'quality' => 'bad', 'reference_images' => ['file:///etc/passwd'], 'prompt' => '']))->assertUnprocessable()->assertJsonValidationErrors(['size', 'quality', 'prompt', 'reference_images.0']);
        $this->postJson('/quote', $this->input(['prompt' => str_repeat('x', 4001)]))->assertUnprocessable();
    }

    public function test_reference_urls_must_be_public_https_approved_hosts(): void
    {
        $this->actingAs($this->user);
        foreach (['http://169.254.169.254/latest/meta-data/', 'https://127.0.0.1/private', 'https://images.unsplash.com.evil.test/image.png', 'https://images.unsplash.com:8443/image.png', 'https://user:pass@images.unsplash.com/image.png', 'https://images.unsplash.com/image.png#fragment'] as $url) {
            $this->postJson('/generations', $this->input(['reference_images' => [$url]]))
                ->assertUnprocessable()->assertJsonValidationErrors('reference_images.0');
        }
        $this->assertDatabaseCount('generations', 0);
        Http::assertNothingSent();

        $this->postJson('/quote', $this->input(['reference_images' => ['https://images.unsplash.com/photo-example']]))
            ->assertOk()->assertJsonStructure(['estimate', 'minimum', 'maximum']);
    }

    public function test_shared_demo_budget_blocks_a_second_account_before_queueing(): void
    {
        $input = $this->input(['quality' => 'high']);
        $maximum = app(Quote::class)->calculate($input, $this->pricing)['maximum'];
        config(['services.pixelforge.daily_budget' => Money::format($maximum + intdiv($maximum, 2))]);

        $this->actingAs($this->user)->post('/generations', $input)->assertRedirect('/studio');
        $second = User::factory()->create();
        $this->actingAs($second)->post('/generations', $this->input(['quality' => 'high']))
            ->assertSessionHasErrors('prompt');

        $this->assertDatabaseCount('generations', 1);
        Queue::assertPushed(SubmitGeneration::class, 1);
    }

    public function test_insufficient_balance_does_not_submit(): void
    {
        $this->user->forceFill(['balance_pico' => 1])->save();
        $this->actingAs($this->user)->post('/generations', $this->input())->assertSessionHasErrors('prompt');
        $this->assertDatabaseCount('generations', 0);
        Queue::assertNothingPushed();
    }

    public function test_reservations_prevent_parallel_overspending(): void
    {
        $this->user->forceFill(['balance_pico' => 510000000000])->save();
        $this->generation();
        $this->actingAs($this->user)->post('/generations', $this->input())->assertSessionHasErrors('prompt');
        $this->assertDatabaseCount('generations', 1);
    }

    public function test_fake_credit_purchase_is_idempotent(): void
    {
        $request = ['request_id' => (string) Str::uuid()];
        $this->actingAs($this->user)->post('/credits', $request)->assertRedirect();
        $this->post('/credits', $request)->assertRedirect();
        $this->assertSame(10000000000000, $this->user->fresh()->balance_pico);
        $this->assertDatabaseCount('credit_purchases', 1);
    }

    public function test_success_duplicate_events_and_polling_charge_exactly_once(): void
    {
        $g = $this->generation();
        $event = $this->event($g);
        $this->webhook($event)->assertNoContent();
        $this->webhook($event)->assertNoContent();
        $this->webhook($this->event($g))->assertNoContent();
        app(SettleGeneration::class)->apply($g->id, $this->remote($g));
        $this->assertDatabaseCount('charges', 1);
        $this->assertDatabaseCount('webhook_events', 2);
        $this->assertDatabaseHas('charges', ['provider_cost_pico' => 305510000000, 'user_charge_pico' => 381887500000]);
        $this->assertSame(4618112500000, $this->user->fresh()->balance_pico);
        $this->assertSame(0, $g->fresh()->reserved_pico);
    }

    public function test_early_webhook_and_late_queued_response_cannot_regress_success(): void
    {
        $g = $this->generation();
        $this->webhook($this->event($g))->assertNoContent();
        app(SettleGeneration::class)->apply($g->id, $this->remote($g, 'queued'));
        $this->webhook($this->event($g, 'processing'))->assertNoContent();
        $this->assertSame('succeeded', $g->fresh()->status);
        $this->assertDatabaseCount('charges', 1);
    }

    public function test_failed_generations_are_free_and_release_reservations(): void
    {
        $g = $this->generation();
        $this->webhook($this->event($g, 'failed'))->assertNoContent();
        $this->webhook($this->event($g, 'processing'))->assertNoContent();
        $this->assertSame('failed', $g->fresh()->status);
        $this->assertSame('generation_failed', $g->fresh()->error_code);
        $this->assertStringContainsString('not charged', $g->fresh()->error_message);
        $this->assertDatabaseCount('charges', 0);
        $this->assertSame(5000000000000, $this->user->fresh()->balance_pico);
        $this->assertSame(0, $g->fresh()->reserved_pico);
    }

    public function test_invalid_and_expired_signatures_are_rejected_without_receipts(): void
    {
        $g = $this->generation();
        $event = $this->event($g);
        $this->webhook($event, null, 'wrong')->assertStatus(400);
        $this->webhook($event, time() - 301)->assertStatus(400);
        $this->webhook($event, time() + 301)->assertStatus(400);
        $this->assertDatabaseCount('webhook_events', 0);
        $this->assertDatabaseCount('charges', 0);
    }

    public function test_missing_usage_rolls_back_event_receipt_for_redelivery(): void
    {
        $g = $this->generation();
        $e = $this->event($g);
        $e['data']['object']['usage'] = [];
        $this->webhook($e)->assertStatus(500);
        $this->assertDatabaseCount('webhook_events', 0);
        $this->assertDatabaseCount('charges', 0);
        $this->assertSame('queued', $g->fresh()->status);
    }

    public function test_event_identifier_cannot_be_reused_with_different_payload(): void
    {
        $g = $this->generation();
        $e = $this->event($g);
        $this->webhook($e)->assertNoContent();
        $e['data']['object']['usage']['output_tokens'] = 0;
        $this->webhook($e)->assertStatus(409);
        $this->assertDatabaseCount('charges', 1);
    }

    public function test_generations_use_their_own_frozen_rates(): void
    {
        $g = $this->generation();
        $remote = $this->remote($g);
        $remote['rates']['output_tokens'] = '80.00';
        app(SettleGeneration::class)->apply($g->id, $remote);
        $this->assertDatabaseHas('charges', ['provider_cost_pico' => 563510000000, 'user_charge_pico' => 704387500000]);
    }

    public function test_submission_uses_stable_provider_idempotency_key(): void
    {
        $g = $this->generation();
        Http::fake(['*/generations' => Http::response($this->remote($g, 'queued'), 202)]);
        (new SubmitGeneration($g->id))->handle(app(Pixelforge::class), app(SettleGeneration::class));
        Http::assertSent(fn ($r) => $r->hasHeader('Idempotency-Key', 'generation:'.$g->id) && $r['metadata']['app_generation_id'] === $g->id);
        $this->assertSame('gen_'.$g->id, $g->fresh()->provider_id);
        Queue::assertPushed(PollGeneration::class);
    }

    public function test_rate_limit_honors_retry_after_without_charging(): void
    {
        $g = $this->generation();
        Http::fake(['*/generations' => Http::response(['error' => ['code' => 'rate_limit_exceeded']], 429, ['Retry-After' => '45'])]);
        (new SubmitGeneration($g->id))->handle(app(Pixelforge::class), app(SettleGeneration::class));
        $this->assertGreaterThanOrEqual(44, now()->diffInSeconds($g->fresh()->next_attempt_at));
        $this->assertDatabaseCount('charges', 0);
        Queue::assertPushed(SubmitGeneration::class);
    }

    public function test_definitive_overload_gives_up_after_six_attempts_and_releases_hold(): void
    {
        $g = $this->generation(['submit_attempts' => 5]);
        Http::fake(['*/generations' => Http::response(['error' => ['code' => 'overloaded']], 503)]);
        (new SubmitGeneration($g->id))->handle(app(Pixelforge::class), app(SettleGeneration::class));
        $this->assertSame('failed', $g->fresh()->status);
        $this->assertSame(0, $g->fresh()->reserved_pico);
        $this->assertDatabaseCount('charges', 0);
        Queue::assertNothingPushed();
    }

    public function test_ambiguous_timeout_keeps_hold_instead_of_claiming_failure(): void
    {
        $g = $this->generation(['submit_attempts' => 5]);
        Http::fake(fn () => throw new ConnectionException('timeout'));
        (new SubmitGeneration($g->id))->handle(app(Pixelforge::class), app(SettleGeneration::class));
        $g->refresh();
        $this->assertTrue($g->needs_attention);
        $this->assertSame('queued', $g->status);
        $this->assertSame(500000000000, $g->reserved_pico);
        $this->assertDatabaseCount('charges', 0);
    }

    public function test_polling_recovers_a_missed_webhook(): void
    {
        $g = $this->generation();
        $g->update(['provider_id' => 'gen_'.$g->id]);
        Http::fake(['*/generations/*' => Http::response($this->remote($g))]);
        (new PollGeneration($g->id))->handle(app(Pixelforge::class), app(SettleGeneration::class));
        $this->assertSame('succeeded', $g->fresh()->status);
        $this->assertDatabaseCount('charges', 1);
    }

    public function test_users_only_see_their_own_history_and_cannot_read_report(): void
    {
        $this->generation();
        $other = User::factory()->create();
        $this->actingAs($other)->get('/studio')->assertInertia(fn (Assert $p) => $p->component('Studio')->has('history.data', 0));
        $this->get('/report')->assertForbidden();
    }

    public function test_report_reconciles_on_utc_completion_day(): void
    {
        $this->user->forceFill(['is_admin' => true])->save();
        $g = $this->generation(['created_at' => '2026-11-15T23:59:00Z']);
        app(SettleGeneration::class)->apply($g->id, $this->remote($g));
        Http::fake(['*/usage*' => Http::response(['data' => [['date' => '2026-11-16', 'generations' => 1, 'cost_usd' => '0.305510000']]])]);
        $this->actingAs($this->user)->get('/report?from=2026-11-16&to=2026-11-16')->assertInertia(fn (Assert $p) => $p->component('Report')->where('daily.0.matches', true)->where('daily.0.profit', '0.076377500000'));
    }

    public function test_authentication_registers_and_logs_out(): void
    {
        $this->post('/register', ['name' => 'New user', 'email' => 'new@example.test', 'password' => 'safe-test-password', 'password_confirmation' => 'safe-test-password'])->assertRedirect('/studio');
        $this->assertAuthenticated();
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->post('/login', ['email' => 'new@example.test', 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => 'new@example.test', 'password' => 'safe-test-password'])->assertRedirect('/studio');
    }

    public function test_login_attempts_for_one_email_are_limited_across_source_addresses(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.$i])
                ->postJson('/login', ['email' => 'victim@example.test', 'password' => 'wrong-password'])
                ->assertUnprocessable();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.21'])
            ->postJson('/login', ['email' => 'VICTIM@example.test', 'password' => 'wrong-password'])
            ->assertTooManyRequests();
    }

    public function test_signed_malformed_json_is_rejected_without_a_receipt(): void
    {
        $raw = '{invalid';
        $time = time();
        $this->call('POST', '/webhooks/pixelforge', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_PIXELFORGE_SIGNATURE' => 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$raw, 'whsec_test_signing_secret')], $raw)->assertStatus(400);
        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_unknown_generation_event_never_creates_a_generation_or_charge(): void
    {
        $g = $this->generation();
        $event = $this->event($g);
        $event['data']['object']['metadata']['app_generation_id'] = (string) Str::uuid();
        $this->webhook($event)->assertNoContent();
        $this->assertDatabaseCount('generations', 1);
        $this->assertDatabaseCount('charges', 0);
    }

    public function test_event_type_must_match_object_state(): void
    {
        $g = $this->generation();
        $event = $this->event($g);
        $event['type'] = 'generation.failed';
        $this->webhook($event)->assertStatus(422);
        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_three_active_generations_block_another_submission(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->generation();
        }
        $this->actingAs($this->user)->post('/generations', $this->input())->assertSessionHasErrors('prompt');
        $this->assertDatabaseCount('generations', 3);
        Queue::assertNothingPushed();
    }

    public function test_daily_limit_failure_does_not_retry_or_charge(): void
    {
        $g = $this->generation();
        Http::fake(['*/generations' => Http::response(['error' => ['code' => 'daily_limit_exceeded']], 429)]);
        (new SubmitGeneration($g->id))->handle(app(Pixelforge::class), app(SettleGeneration::class));
        $this->assertSame('failed', $g->fresh()->status);
        $this->assertSame(0, $g->fresh()->reserved_pico);
        Queue::assertNothingPushed();
    }

    public function test_late_provider_error_cannot_overwrite_a_concurrent_webhook_success(): void
    {
        $g = $this->generation();
        Http::fake(function () use ($g) {
            app(SettleGeneration::class)->apply($g->id, $this->remote($g));

            return Http::response(['error' => ['code' => 'overloaded']], 503);
        });
        (new SubmitGeneration($g->id))->handle(app(Pixelforge::class), app(SettleGeneration::class));
        $this->assertSame('succeeded', $g->fresh()->status);
        $this->assertDatabaseCount('charges', 1);
    }

    public function test_provider_usage_outage_is_never_reported_as_reconciled(): void
    {
        $this->user->forceFill(['is_admin' => true])->save();
        Http::fake(['*/usage*' => Http::response([], 503)]);
        $this->actingAs($this->user)->get('/report?from=2026-11-16&to=2026-11-16')->assertInertia(fn (Assert $p) => $p->component('Report')->where('daily.0.matches', null)->where('providerError', fn ($x) => is_string($x)));
    }

    public function test_public_registration_cannot_grant_admin_privileges(): void
    {
        $this->post('/register', ['name' => 'Public user', 'email' => 'public@example.test', 'password' => 'safe-test-password', 'password_confirmation' => 'safe-test-password', 'is_admin' => true])->assertRedirect('/studio');
        $this->get('/report')->assertForbidden();
        $this->assertFalse(User::where('email', 'public@example.test')->first()->is_admin);
    }

    public function test_quote_requests_do_not_consume_generation_rate_limits(): void
    {
        $this->actingAs($this->user);
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/quote', $this->input())->assertOk();
        }
        $this->post('/generations', $this->input())->assertRedirect('/studio');
        $this->assertDatabaseCount('generations', 1);
    }
}
