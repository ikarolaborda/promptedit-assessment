<?php

namespace Tests\Feature;

use App\Models\CreditOrder;
use App\Models\User;
use App\Services\Money;
use App\Services\StripeBilling;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StripeBillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['services.foundry.enabled' => true, 'services.stripe.secret' => 'sk_test_fixture', 'services.stripe.price_id' => 'price_credits', 'services.stripe.webhook_secret' => 'whsec_fixture', 'services.stripe.livemode' => false]);
        Http::preventStrayRequests();
    }

    private function user(): User
    {
        $user = User::factory()->create();
        config(['services.stripe.test_email' => $user->email]);

        return $user;
    }

    private function order(User $user, bool $live = false): CreditOrder
    {
        return CreditOrder::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'amount_cents' => 200, 'currency' => 'usd', 'price_id' => 'price_credits', 'livemode' => $live, 'stripe_session_id' => 'cs_fixture']);
    }

    private function paid(CreditOrder $order): array
    {
        return ['id' => 'cs_fixture', 'client_reference_id' => $order->id, 'metadata' => ['app' => 'frame-studio', 'order_id' => $order->id],
            'livemode' => $order->livemode, 'amount_total' => 200, 'currency' => 'usd', 'payment_status' => 'paid',
            'payment_intent' => ['id' => 'pi_fixture', 'latest_charge' => ['amount_refunded' => 0]], 'line_items' => ['data' => [['price' => ['id' => 'price_credits'], 'quantity' => 1]]]];
    }

    private function webhook(array $data, string $type = 'checkout.session.completed', string $id = 'evt_fixture', int $offset = 0, ?string $secret = null)
    {
        $body = json_encode(['id' => $id, 'object' => 'event', 'type' => $type, 'livemode' => false, 'data' => ['object' => $data]]);
        $time = time() + $offset;
        $signature = hash_hmac('sha256', $time.'.'.$body, $secret ?? 'whsec_fixture');

        return $this->call('POST', '/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 't='.$time.',v1='.$signature], $body);
    }

    public function test_checkout_is_fixed_price_and_idempotent(): void
    {
        $user = $this->user();
        $id = (string) Str::uuid();
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_fixture', 'url' => 'https://checkout.stripe.com/c/pay/cs_fixture', 'livemode' => false])]);
        $billing = app(StripeBilling::class);
        $this->assertSame($billing->checkout($user, $id), $billing->checkout($user, $id));
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['line_items'][0]['price'] === 'price_credits' && $r['line_items'][0]['quantity'] === 1 && $r['mode'] === 'payment' && $r->hasHeader('Idempotency-Key', 'frame-credit:'.$id));
        $this->assertDatabaseCount('credit_orders', 1);
        $this->assertSame(0, $user->fresh()->test_balance_pico);
    }

    public function test_paid_webhook_and_redirect_credit_exactly_once(): void
    {
        $user = $this->user();
        $order = $this->order($user);
        $paid = $this->paid($order);
        Http::fake(['api.stripe.com/*' => Http::response($paid)]);
        $event = $paid;
        $event['payment_intent'] = 'pi_fixture';
        unset($event['line_items']);
        $this->webhook($event)->assertNoContent();
        $this->webhook($event)->assertNoContent();
        $this->actingAs($user)->get('/billing/success?session_id=cs_fixture')->assertRedirect('/studio?mode=real');
        $this->assertSame(Money::parse('2'), $user->fresh()->test_balance_pico);
        $this->assertSame(0, $user->fresh()->paid_balance_pico);
        $this->assertSame(Money::parse('5'), $user->fresh()->balance_pico);
        $this->assertDatabaseCount('stripe_events', 1);
    }

    public function test_unpaid_redirect_does_not_add_credits(): void
    {
        $user = $this->user();
        $order = $this->order($user);
        $paid = $this->paid($order);
        $paid['payment_status'] = 'unpaid';
        Http::fake(['api.stripe.com/*' => Http::response($paid)]);
        $this->actingAs($user)->get('/billing/success?session_id=cs_fixture')->assertRedirect();
        $this->assertNull($order->fresh()->credited_at);
        $this->assertSame(0, $user->fresh()->test_balance_pico);
    }

    public function test_bad_or_expired_signatures_and_wrong_mode_are_rejected(): void
    {
        $this->webhook([], secret: 'wrong')->assertStatus(400);
        $this->webhook([], offset: -301)->assertStatus(400);
        config(['services.stripe.livemode' => true]);
        $this->webhook([])->assertStatus(400);
        $this->assertDatabaseCount('stripe_events', 0);
        Http::assertNothingSent();
    }

    public function test_wrong_amount_currency_price_or_owner_never_credits(): void
    {
        $user = $this->user();
        $order = $this->order($user);
        foreach (['amount_total' => 1, 'currency' => 'eur', 'client_reference_id' => (string) Str::uuid(), 'livemode' => true] as $key => $value) {
            $paid = $this->paid($order);
            $paid[$key] = $value;
            try {
                app(StripeBilling::class)->fulfill($paid);
                $this->fail('Mismatch was accepted');
            } catch (ValidationException $e) {
                $this->assertSame(0, $user->fresh()->test_balance_pico);
            }
        }
        $paid = $this->paid($order);
        $paid['line_items']['data'][0]['price']['id'] = 'price_other';
        $this->expectException(ValidationException::class);
        app(StripeBilling::class)->fulfill($paid);
    }

    public function test_return_url_cannot_access_another_users_payment(): void
    {
        $user = $this->user();
        $this->order($user);
        $this->actingAs(User::factory()->create())->get('/billing/success?session_id=cs_fixture')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_refund_reduces_credit_once_even_after_spend(): void
    {
        $user = $this->user();
        $order = $this->order($user);
        $billing = app(StripeBilling::class);
        $billing->fulfill($this->paid($order));
        $user->forceFill(['test_balance_pico' => Money::parse('0.50')])->save();
        $charge = ['payment_intent' => 'pi_fixture', 'livemode' => false, 'currency' => 'usd', 'amount_refunded' => 200];
        $this->webhook($charge, 'charge.refunded')->assertNoContent();
        $this->webhook($charge, 'charge.refunded', 'evt_repeat')->assertNoContent();
        $this->assertSame(Money::parse('-1.50'), $user->fresh()->test_balance_pico);
        $this->assertSame(200, $order->fresh()->refunded_cents);
    }

    public function test_refund_before_fulfillment_is_deducted_from_initial_credit(): void
    {
        $user = $this->user();
        $order = $this->order($user);
        $paid = $this->paid($order);
        $paid['payment_intent']['latest_charge']['amount_refunded'] = 200;
        app(StripeBilling::class)->fulfill($paid);
        $this->assertSame(0, $user->fresh()->test_balance_pico);
        $this->assertSame(200, $order->fresh()->refunded_cents);
    }

    public function test_live_payment_credits_only_the_live_wallet(): void
    {
        $user = $this->user();
        $order = $this->order($user, true);
        app(StripeBilling::class)->fulfill($this->paid($order));
        $this->assertSame(Money::parse('2'), $user->fresh()->paid_balance_pico);
        $this->assertSame(0, $user->fresh()->test_balance_pico);
    }

    public function test_demo_and_non_operator_test_users_cannot_buy_credits(): void
    {
        $user = $this->user();
        $user->forceFill(['is_admin' => true])->save();
        $this->assertFalse(app(StripeBilling::class)->available($user));
        $this->assertFalse(app(StripeBilling::class)->available(User::factory()->create()));
        config(['services.foundry.enabled' => false]);
        $user->forceFill(['is_admin' => false])->save();
        $this->assertFalse(app(StripeBilling::class)->available($user));
    }
}
