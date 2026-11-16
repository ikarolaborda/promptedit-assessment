<?php

namespace Tests\Feature;

use App\Jobs\GenerateFoundryImage;
use App\Models\Generation;
use App\Models\User;
use App\Services\FoundryImages;
use App\Services\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PaidGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['services.foundry.enabled' => true, 'services.foundry.endpoint' => 'https://foundry.example.test', 'services.foundry.key' => 'fixture', 'services.stripe.livemode' => true]);
        Queue::fake();
        Http::preventStrayRequests();
    }

    private function input(): array
    {
        return ['mode' => 'real', 'prompt' => 'A green cabin', 'size' => '1024x1024', 'quality' => 'low', 'reference_images' => [], 'client_request_id' => (string) Str::uuid()];
    }

    private function user(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['paid_balance_pico' => Money::parse('2')])->save();

        return $user;
    }

    private function providerResult(): array
    {
        return ['data' => [['b64_json' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j0l8AAAAASUVORK5CYII=']],
            'usage' => ['input_tokens' => 10, 'input_tokens_details' => ['text_tokens' => 10, 'image_tokens' => 0], 'output_tokens' => 600]];
    }

    public function test_demo_credits_cannot_fund_real_images(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/generations', $this->input())->assertSessionHasErrors('prompt');
        $this->assertDatabaseCount('generations', 0);
        Queue::assertNothingPushed();
    }

    public function test_success_debits_paid_credits_once_and_keeps_demo_balance(): void
    {
        $user = $this->user();
        $input = $this->input();
        $this->actingAs($user)->post('/generations', $input)->assertRedirect('/studio?mode=real');
        $this->post('/generations', $input)->assertRedirect('/studio?mode=real');
        Queue::assertPushed(GenerateFoundryImage::class, 1);
        $g = Generation::first();
        $this->assertGreaterThan(0, $g->reserved_pico);
        $this->assertSame(Money::parse('2'), $user->fresh()->paid_balance_pico);
        Http::fake(['foundry.example.test/*' => Http::response($this->providerResult())]);
        $job = new GenerateFoundryImage($g->id);
        $job->handle(app(FoundryImages::class));
        $job->handle(app(FoundryImages::class));
        Http::assertSentCount(1);
        $this->assertDatabaseCount('charges', 1);
        $this->assertDatabaseCount('generated_assets', 1);
        $this->assertSame(Money::parse('1.993975'), $user->fresh()->paid_balance_pico);
        $this->assertSame(Money::parse('5'), $user->fresh()->balance_pico);
        $this->assertSame(0, $g->fresh()->reserved_pico);
        $this->assertSame(0, DB::table('image_budgets')->value('reserved_pico'));
        $this->assertSame(Money::parse('0.00482'), DB::table('image_budgets')->value('spent_pico'));
        $this->get('/generations/'.$g->id.'/image')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->actingAs(User::factory()->create())->get('/generations/'.$g->id.'/image')->assertNotFound();
    }

    public function test_client_decline_releases_hold_without_a_charge(): void
    {
        $user = $this->user();
        $this->actingAs($user)->post('/generations', $this->input());
        $g = Generation::first();
        Http::fake(['foundry.example.test/*' => Http::response(['error' => ['code' => 'content_filter']], 400)]);
        (new GenerateFoundryImage($g->id))->handle(app(FoundryImages::class));
        $this->assertSame('failed', $g->fresh()->status);
        $this->assertSame(Money::parse('2'), $user->fresh()->paid_balance_pico);
        $this->assertSame(0, $g->fresh()->reserved_pico);
        $this->assertSame(0, DB::table('image_budgets')->value('reserved_pico'));
        $this->assertDatabaseCount('charges', 0);
    }

    public function test_ambiguous_failure_is_not_resubmitted_or_charged(): void
    {
        $user = $this->user();
        $this->actingAs($user)->post('/generations', $this->input());
        $g = Generation::first();
        Http::fake(['foundry.example.test/*' => Http::failedConnection()]);
        $job = new GenerateFoundryImage($g->id);
        $job->handle(app(FoundryImages::class));
        $job->handle(app(FoundryImages::class));
        $this->assertTrue($g->fresh()->needs_attention);
        $this->assertGreaterThan(0, $g->fresh()->reserved_pico);
        $this->assertSame(1, $g->fresh()->submit_attempts);
        $this->assertSame(Money::parse('2'), $user->fresh()->paid_balance_pico);
        $this->assertDatabaseCount('charges', 0);
    }

    public function test_missing_usage_or_corrupt_image_never_debits_customer(): void
    {
        $user = $this->user();
        $this->actingAs($user)->post('/generations', $this->input());
        $g = Generation::first();
        Http::fake(['foundry.example.test/*' => Http::response(['data' => [['b64_json' => 'bad']]])]);
        (new GenerateFoundryImage($g->id))->handle(app(FoundryImages::class));
        $this->assertTrue($g->fresh()->needs_attention);
        $this->assertDatabaseCount('charges', 0);
        $this->assertSame(Money::parse('2'), $user->fresh()->paid_balance_pico);
    }

    public function test_daily_budget_blocks_before_provider_submission(): void
    {
        config(['services.foundry.daily_budget' => '0.001']);
        $this->actingAs($this->user())->post('/generations', $this->input())->assertSessionHasErrors('prompt');
        $this->assertDatabaseCount('generations', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_paid_balance_is_reserved_across_pending_requests(): void
    {
        $user = $this->user();
        $input = $this->input();
        $maximum = app(FoundryImages::class)->quote($input)['maximum'];
        $user->forceFill(['paid_balance_pico' => $maximum])->save();
        $this->actingAs($user)->post('/generations', $input)->assertRedirect();
        $this->post('/generations', $this->input())->assertSessionHasErrors('prompt');
        $this->assertDatabaseCount('generations', 1);
    }

    public function test_quoted_cap_limits_customer_charge_even_if_provider_usage_is_higher(): void
    {
        $user = $this->user();
        $this->actingAs($user)->post('/generations', $this->input());
        $g = Generation::first();
        $response = $this->providerResult();
        $response['usage']['output_tokens'] = 20000;
        Http::fake(['foundry.example.test/*' => Http::response($response)]);
        (new GenerateFoundryImage($g->id))->handle(app(FoundryImages::class));
        $this->assertSame($g->maximum_cost_pico, $g->fresh()->charge->user_charge_pico);
        $this->assertSame(Money::parse('2') - $g->maximum_cost_pico, $user->fresh()->paid_balance_pico);
    }

    public function test_test_wallet_and_histories_are_isolated_from_live_wallet(): void
    {
        $user = $this->user();
        config(['services.stripe.livemode' => false, 'services.stripe.test_email' => $user->email]);
        $this->actingAs($user)->post('/generations', $this->input())->assertSessionHasErrors('prompt');
        $user->forceFill(['test_balance_pico' => Money::parse('2')])->save();
        $this->post('/generations', $this->input())->assertRedirect();
        $g = Generation::first();
        $this->assertSame('test', $g->wallet);
        config(['services.stripe.livemode' => true]);
        $this->get('/studio?mode=real')->assertInertia(fn (Assert $page) => $page->where('history.data', [])->where('stats.balance', '2.000000000000'));
    }

    public function test_shared_demo_and_disabled_feature_cannot_generate_real_images(): void
    {
        $user = $this->user();
        $user->forceFill(['is_admin' => true])->save();
        $this->actingAs($user)->post('/generations', $this->input())->assertForbidden();
        $user->forceFill(['is_admin' => false])->save();
        config(['services.foundry.enabled' => false]);
        $this->post('/generations', $this->input())->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_real_reference_images_are_rejected_before_reserving_money(): void
    {
        $input = $this->input();
        $input['reference_images'] = ['http://127.0.0.1/private'];
        $this->actingAs($this->user())->post('/generations', $input)->assertSessionHasErrors('reference_images');
        $this->assertDatabaseCount('generations', 0);
        Http::assertNothingSent();
    }

    public function test_test_mode_leaves_other_users_in_the_demo(): void
    {
        config(['services.stripe.livemode' => false, 'services.stripe.test_email' => 'operator@example.test']);
        $this->actingAs(User::factory()->create())->get('/studio')->assertOk()->assertInertia(fn ($page) => $page->where('mode', 'demo')->where('realEnabled', false));
    }
}
