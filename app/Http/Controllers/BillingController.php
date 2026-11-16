<?php

namespace App\Http\Controllers;

use App\Models\CreditOrder;
use App\Services\StripeBilling;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BillingController extends Controller
{
    public function checkout(Request $request, StripeBilling $billing)
    {
        $input = $request->validate(['request_id' => 'required|uuid']);
        try {
            $url = $billing->checkout($request->user(), $input['request_id']);
        } catch (HttpClientException $e) {
            return back()->withErrors(['payment' => 'Checkout is temporarily unavailable. No payment was taken. Please try again.']);
        }

        return Inertia::location($url);
    }

    public function success(Request $request, StripeBilling $billing)
    {
        $data = $request->validate(['session_id' => 'required|string|starts_with:cs_|max:255']);
        $order = CreditOrder::where('stripe_session_id', $data['session_id'])->firstOrFail();
        abort_unless($order->user_id === $request->user()->id, 403);
        try {
            $billing->reconcile($data['session_id'], $request->user()->id);
        } catch (HttpClientException $e) {
            return redirect('/studio?mode=real')->with('message', 'Your payment is being verified. Credits will appear after confirmation.');
        }

        $order->refresh();
        $message = ! $order->credited_at ? 'Your payment is being verified. Credits appear only after confirmation.'
            : ($order->refunded_cents ? 'Your payment refund has been reflected in your credits.' : '$2.00 credits added. Your payment is confirmed.');

        return redirect('/studio?mode=real')->with('message', $message);
    }
}
