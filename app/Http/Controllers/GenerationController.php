<?php

namespace App\Http\Controllers;

use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\Money;
use App\Services\Pixelforge;
use App\Services\Quote;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class GenerationController extends Controller
{
    private function input(Request $request, bool $withId = false): array
    {
        return $request->validate([
            'prompt' => ['required', 'string', 'max:4000'],
            'size' => ['required', Rule::in(['1024x1024', '1536x1024', '1024x1536'])],
            'quality' => ['required', Rule::in(['low', 'medium', 'high'])],
            'reference_images' => ['present', 'array', 'max:4'],
            'reference_images.*' => ['required', 'string', 'url:http,https', 'max:2048'],
            ...($withId ? ['client_request_id' => ['required', 'uuid']] : []),
        ]);
    }

    public function index(Request $request)
    {
        $history = Generation::where('user_id', $request->user()->id)->with('charge')->latest()->paginate(12);
        $history->through(fn (Generation $g) => [
            'id' => $g->id, 'prompt' => $g->prompt, 'size' => $g->size, 'quality' => $g->quality,
            'status' => $g->status, 'estimate' => Money::format($g->estimated_cost_pico),
            'actual' => $g->charge ? Money::format($g->charge->user_charge_pico) : ($g->status === 'failed' ? Money::format(0) : null),
            'image_url' => $g->image_url, 'image_expired' => $g->image_url_expires_at?->isPast() ?? false,
            'created_at' => $g->created_at->toIso8601String(), 'error_message' => $g->error_message,
            'usage' => $g->usage, 'needs_attention' => $g->needs_attention,
        ]);

        return Inertia::render('Studio', ['history' => $history,
            'stats' => [
                'total' => Generation::where('user_id', $request->user()->id)->count(),
                'active' => Generation::where('user_id', $request->user()->id)->whereIn('status', ['queued', 'processing'])->count(),
                'spent' => Money::format((int) DB::table('charges')->where('user_id', $request->user()->id)->sum('user_charge_pico')),
                'balance' => Money::format((int) $request->user()->balance_pico),
                'reserved' => Money::format((int) Generation::where('user_id', $request->user()->id)->sum('reserved_pico')),
                'available' => Money::format((int) $request->user()->balance_pico - (int) Generation::where('user_id', $request->user()->id)->sum('reserved_pico')),
            ],
        ]);
    }

    public function quote(Request $request, Pixelforge $api, Quote $quote)
    {
        $input = $this->input($request);
        try {
            $amounts = $quote->calculate($input, $api->pricing());
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'Live pricing is unavailable. Please try again shortly.'], 503);
        }

        return response()->json(array_map(fn ($amount) => Money::format($amount), $amounts));
    }

    public function store(Request $request, Pixelforge $api, Quote $quote)
    {
        $input = $this->input($request, true);
        $requestId = $input['client_request_id'];
        unset($input['client_request_id']);
        $hash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
        // Replays work even during a pricing outage and never requote an accepted request.
        if ($existing = Generation::where('user_id', $request->user()->id)->where('client_request_id', $requestId)->first()) {
            abort_unless(hash_equals($existing->request_hash, $hash), 409, 'This request identifier was used with different inputs.');

            return redirect('/studio');
        }
        try {
            $amounts = $quote->calculate($input, $api->pricing());
        } catch (\Throwable $e) {
            report($e);
            throw ValidationException::withMessages(['prompt' => 'Live pricing is unavailable. Your generation was not submitted. Please try again.']);
        }
        DB::transaction(function () use ($request, $requestId, $input, $hash, $amounts) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $existing = Generation::where('user_id', $request->user()->id)->where('client_request_id', $requestId)->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_hash, $hash), 409);

                return;
            }
            if (Generation::where('user_id', $request->user()->id)->whereIn('status', ['queued', 'processing'])->count() >= 3) {
                throw ValidationException::withMessages(['prompt' => 'You already have three generations in progress. Please wait for one to finish.']);
            }
            $reserved = (int) Generation::where('user_id', $user->id)->sum('reserved_pico');
            if ($user->balance_pico - $reserved < $amounts['maximum']) {
                throw ValidationException::withMessages(['prompt' => 'Not enough available credits for the estimated maximum. Add demo credits or wait for a pending generation to finish.']);
            }
            $g = Generation::create($input + [
                'user_id' => $request->user()->id, 'client_request_id' => $requestId, 'request_hash' => $hash,
                'estimated_cost_pico' => $amounts['estimate'], 'minimum_cost_pico' => $amounts['minimum'], 'maximum_cost_pico' => $amounts['maximum'],
                'reserved_pico' => $amounts['maximum'],
            ]);
            // With the database queue this insert shares the same database transaction.
            SubmitGeneration::dispatch($g->id);
        }, 5);

        return redirect('/studio')->with('message', 'Your generation is queued. We will update its status here.');
    }
}
