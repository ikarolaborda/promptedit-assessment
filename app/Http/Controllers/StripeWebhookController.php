<?php

namespace App\Http\Controllers;

use App\Models\CreditOrder;
use App\Services\StripeBilling;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Stripe\Webhook;

class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripeBilling $billing)
    {
        abort_unless(config('services.stripe.webhook_secret'), 503);
        abort_if(strlen($request->getContent()) > 262144, 413);
        try {
            $event = Webhook::constructEvent($request->getContent(), $request->header('Stripe-Signature', ''), config('services.stripe.webhook_secret'), 300)->toArray();
        } catch (\Throwable $e) {
            abort(400, 'Invalid webhook signature.');
        }
        abort_unless(($event['livemode'] ?? null) === (bool) config('services.stripe.livemode'), 400);
        $hash = hash('sha256', $request->getContent());
        $seen = DB::table('stripe_events')->where('id', $event['id'])->first();
        if ($seen) {
            abort_unless(hash_equals($seen->payload_hash, $hash), 409);

            return response()->noContent();
        }
        $object = $event['data']['object'];
        if (in_array($event['type'], ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
            if (($object['payment_status'] ?? '') === 'paid') {
                $billing->reconcile($object['id']);
            }
        } elseif ($event['type'] === 'charge.refunded') {
            $billing->refund($object);
        } elseif ($event['type'] === 'checkout.session.expired') {
            CreditOrder::where('stripe_session_id', $object['id'])->whereNull('credited_at')->update(['status' => 'expired']);
        }
        DB::table('stripe_events')->insertOrIgnore(['id' => $event['id'], 'payload_hash' => $hash, 'processed_at' => now()]);

        return response()->noContent();
    }
}
