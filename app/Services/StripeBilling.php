<?php

namespace App\Services;

use App\Models\CreditOrder;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class StripeBilling
{
    public function available(User $user): bool
    {
        return ! $user->is_admin && config('services.foundry.enabled') && config('services.stripe.secret') && config('services.stripe.price_id')
            && (config('services.stripe.livemode') || ($user->email_verified_at && $user->email === config('services.stripe.test_email')));
    }

    public function client(): PendingRequest
    {
        return Http::baseUrl('https://api.stripe.com/v1')->withToken(config('services.stripe.secret'))->acceptJson()->asForm()->connectTimeout(5)->timeout(20);
    }

    public function checkout(User $user, string $id): string
    {
        abort_unless($this->available($user), 503, 'Credit purchases are not currently available.');
        $order = DB::transaction(function () use ($user, $id) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $order = CreditOrder::find($id);
            if ($order) {
                abort_unless($order->user_id === $user->id && $order->livemode === (bool) config('services.stripe.livemode'), 409);
                abort_if($order->credited_at || $order->status === 'expired', 409, 'Start a new purchase.');

                return $order;
            }

            return CreditOrder::create(['id' => $id, 'user_id' => $user->id, 'amount_cents' => 200, 'currency' => 'usd',
                'price_id' => config('services.stripe.price_id'), 'livemode' => (bool) config('services.stripe.livemode')]);
        }, 5);
        if ($order->checkout_url) {
            return $order->checkout_url;
        }
        $data = $this->client()->withHeaders(['Idempotency-Key' => 'frame-credit:'.$order->id])->post('/checkout/sessions', [
            'mode' => 'payment', 'payment_method_types' => ['card'],
            'line_items' => [['price' => $order->price_id, 'quantity' => 1]],
            'client_reference_id' => $order->id,
            'metadata' => ['app' => 'frame-studio', 'order_id' => $order->id],
            'payment_intent_data' => ['metadata' => ['app' => 'frame-studio', 'order_id' => $order->id]],
            'success_url' => rtrim(config('app.url'), '/').'/billing/success?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => rtrim(config('app.url'), '/').'/studio?mode=real&payment=cancelled',
        ])->throw()->json();
        abort_unless(($data['livemode'] ?? null) === $order->livemode && is_string($data['id'] ?? null)
            && str_starts_with($data['url'] ?? '', 'https://checkout.stripe.com/'), 502, 'Checkout could not be verified.');
        $order->update(['stripe_session_id' => $data['id'], 'checkout_url' => $data['url']]);

        return $data['url'];
    }

    public function reconcile(string $sessionId, ?int $userId = null): void
    {
        $order = CreditOrder::where('stripe_session_id', $sessionId)->first();
        if (! $order) {
            return;
        }
        if ($userId !== null) {
            abort_unless($order->user_id === $userId, 403);
        }
        if ($order->credited_at) {
            return;
        }
        $session = $this->client()->get('/checkout/sessions/'.rawurlencode($sessionId), ['expand' => ['line_items', 'payment_intent.latest_charge']])->throw()->json();
        if (($session['payment_status'] ?? '') !== 'paid') {
            return;
        }
        $this->fulfill($session);
    }

    public function fulfill(array $session): void
    {
        $id = $session['metadata']['order_id'] ?? null;
        $order = is_string($id) ? CreditOrder::find($id) : null;
        if (! $order || ($session['metadata']['app'] ?? '') !== 'frame-studio') {
            return;
        }
        $lines = $session['line_items']['data'] ?? [];
        $intent = $session['payment_intent'] ?? [];
        $refunded = $intent['latest_charge']['amount_refunded'] ?? null;
        if (($session['id'] ?? '') !== $order->stripe_session_id || ($session['client_reference_id'] ?? '') !== $order->id
            || ($session['livemode'] ?? null) !== $order->livemode || ($session['amount_total'] ?? null) !== $order->amount_cents
            || ($session['currency'] ?? '') !== $order->currency || ($session['payment_status'] ?? '') !== 'paid'
            || count($lines) !== 1 || ($lines[0]['price']['id'] ?? '') !== $order->price_id || ($lines[0]['quantity'] ?? null) !== 1
            || ! is_string($intent['id'] ?? null) || ! is_int($refunded) || $refunded < 0 || $refunded > $order->amount_cents) {
            throw ValidationException::withMessages(['payment' => 'Payment details could not be verified.']);
        }
        DB::transaction(function () use ($order, $intent, $refunded) {
            $user = User::whereKey($order->user_id)->lockForUpdate()->firstOrFail();
            $locked = CreditOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->credited_at) {
                return;
            }
            $user->increment($locked->livemode ? 'paid_balance_pico' : 'test_balance_pico', ($locked->amount_cents - max($locked->refunded_cents, $refunded)) * 10000000000);
            $totalRefunded = max($locked->refunded_cents, $refunded);
            $status = $totalRefunded === $locked->amount_cents ? 'refunded' : ($totalRefunded ? 'partially_refunded' : 'paid');
            $locked->update(['status' => $status, 'payment_intent_id' => $intent['id'], 'credited_at' => now(), 'refunded_cents' => $totalRefunded]);
        }, 5);
    }

    public function refund(array $charge): void
    {
        $order = CreditOrder::where('payment_intent_id', $charge['payment_intent'] ?? '')->first();
        if (! $order || ($charge['livemode'] ?? null) !== $order->livemode || ($charge['currency'] ?? '') !== $order->currency
            || ! is_int($charge['amount_refunded'] ?? null) || $charge['amount_refunded'] < 0 || $charge['amount_refunded'] > $order->amount_cents) {
            return;
        }
        DB::transaction(function () use ($order, $charge) {
            $user = User::whereKey($order->user_id)->lockForUpdate()->firstOrFail();
            $locked = CreditOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $delta = max(0, $charge['amount_refunded'] - $locked->refunded_cents);
            if (! $delta || ! $locked->credited_at) {
                return;
            }
            $user->decrement($locked->livemode ? 'paid_balance_pico' : 'test_balance_pico', $delta * 10000000000);
            $locked->update(['refunded_cents' => $charge['amount_refunded'], 'status' => $charge['amount_refunded'] === $order->amount_cents ? 'refunded' : 'partially_refunded']);
        }, 5);
    }
}
