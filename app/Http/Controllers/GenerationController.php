<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateFoundryImage;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\DemoBudget;
use App\Services\FoundryImages;
use App\Services\Money;
use App\Services\Pixelforge;
use App\Services\Quote;
use App\Services\StripeBilling;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class GenerationController extends Controller
{
    private function realEnabled(User $user): bool
    {
        return config('services.foundry.enabled') && ! $user->is_admin
            && (config('services.stripe.livemode') || ($user->email_verified_at && $user->email === config('services.stripe.test_email')));
    }

    private function mode(Request $request): string
    {
        $mode = $request->input('mode', $this->realEnabled($request->user()) ? 'real' : 'demo');
        abort_unless(in_array($mode, ['real', 'demo'], true), 422);
        if ($mode === 'real') {
            abort_unless($this->realEnabled($request->user()), 403);
        }

        return $mode;
    }

    private function wallet(Request $request, string $mode): string
    {
        return $mode === 'demo' ? 'demo' : (config('services.stripe.livemode') ? 'live' : 'test');
    }

    private function input(Request $request, bool $withId = false): array
    {
        $data = $request->validate([
            'prompt' => ['required', 'string', 'max:4000'],
            'size' => ['required', Rule::in(['1024x1024', '1536x1024', '1024x1536'])],
            'quality' => ['required', Rule::in(['low', 'medium', 'high'])],
            'reference_images' => ['present', 'array', 'max:4'],
            'reference_images.*' => ['required', 'string', 'url:http,https', 'max:2048', function (string $attribute, mixed $value, \Closure $fail) use ($request): void {
                if ($this->mode($request) === 'real') {
                    return;
                }
                $parts = is_string($value) ? parse_url($value) : false;
                $host = is_array($parts) ? strtolower($parts['host'] ?? '') : '';
                if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ! in_array($host, config('services.pixelforge.reference_hosts'), true)
                    || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])) {
                    $fail('Use a public HTTPS image from an approved image host.');
                }
            }],
            ...($withId ? ['client_request_id' => ['required', 'uuid']] : []),
        ]);
        if ($this->mode($request) === 'real' && $data['reference_images']) {
            throw ValidationException::withMessages(['reference_images' => 'Reference images are currently supported in the demo only.']);
        }

        return $data;
    }

    public function index(Request $request)
    {
        $mode = $this->mode($request);
        $wallet = $this->wallet($request, $mode);
        $balanceColumn = $wallet === 'demo' ? 'balance_pico' : ($wallet === 'live' ? 'paid_balance_pico' : 'test_balance_pico');
        $query = Generation::where('user_id', $request->user()->id)->where('wallet', $wallet);
        $history = (clone $query)->with('charge')->latest()->paginate(12)->withQueryString();
        $history->through(fn (Generation $g) => [
            'id' => $g->id, 'prompt' => $g->prompt, 'size' => $g->size, 'quality' => $g->quality,
            'status' => $g->status, 'estimate' => Money::format($g->estimated_cost_pico),
            'actual' => $g->charge ? Money::format($g->charge->user_charge_pico) : ($g->status === 'failed' ? Money::format(0) : null),
            'image_url' => $g->image_url, 'image_expired' => $g->image_url_expires_at?->isPast() ?? false,
            'created_at' => $g->created_at->toIso8601String(), 'error_message' => $g->error_message,
            'usage' => $g->usage, 'needs_attention' => $g->needs_attention,
        ]);

        return Inertia::render('Studio', ['history' => $history, 'mode' => $mode, 'realEnabled' => $this->realEnabled($request->user()), 'testMode' => $wallet === 'test', 'canBuy' => app(StripeBilling::class)->available($request->user()),
            'stats' => [
                'total' => (clone $query)->count(),
                'active' => (clone $query)->whereIn('status', ['queued', 'processing'])->count(),
                'spent' => Money::format((int) DB::table('charges')->whereIn('generation_id', (clone $query)->select('id'))->sum('user_charge_pico')),
                'balance' => Money::format((int) $request->user()->{$balanceColumn}),
                'reserved' => Money::format((int) (clone $query)->sum('reserved_pico')),
                'available' => Money::format((int) $request->user()->{$balanceColumn} - (int) (clone $query)->sum('reserved_pico')),
            ],
        ]);
    }

    public function quote(Request $request, Pixelforge $api, Quote $quote)
    {
        $input = $this->input($request);
        try {
            $amounts = $this->mode($request) === 'real' ? app(FoundryImages::class)->quote($input) : $quote->calculate($input, $api->pricing());
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'Live pricing is unavailable. Please try again shortly.'], 503);
        }

        return response()->json(array_map(fn ($amount) => Money::format($amount), $amounts));
    }

    public function store(Request $request, Pixelforge $api, Quote $quote)
    {
        $input = $this->input($request, true);
        $mode = $this->mode($request);
        $wallet = $this->wallet($request, $mode);
        $requestId = $input['client_request_id'];
        unset($input['client_request_id']);
        $hash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
        // Replays work even during a pricing outage and never requote an accepted request.
        if ($existing = Generation::where('user_id', $request->user()->id)->where('client_request_id', $requestId)->first()) {
            abort_unless($existing->wallet === $wallet && hash_equals($existing->request_hash, $hash), 409, 'This request identifier was used with different inputs.');

            return redirect('/studio'.($mode === 'real' || $this->realEnabled($request->user()) ? '?mode='.$mode : ''));
        }
        try {
            $amounts = $this->mode($request) === 'real' ? app(FoundryImages::class)->quote($input) : $quote->calculate($input, $api->pricing());
        } catch (\Throwable $e) {
            report($e);
            throw ValidationException::withMessages(['prompt' => 'Live pricing is unavailable. Your generation was not submitted. Please try again.']);
        }
        DB::transaction(function () use ($request, $requestId, $input, $hash, $amounts, $mode, $wallet) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $existing = Generation::where('user_id', $request->user()->id)->where('client_request_id', $requestId)->first();
            if ($existing) {
                abort_unless($existing->wallet === $wallet && hash_equals($existing->request_hash, $hash), 409);

                return;
            }
            if (Generation::where('user_id', $request->user()->id)->whereIn('status', ['queued', 'processing'])->count() >= 3) {
                throw ValidationException::withMessages(['prompt' => 'You already have three generations in progress. Please wait for one to finish.']);
            }
            $reserved = (int) Generation::where('user_id', $user->id)->where('wallet', $wallet)->sum('reserved_pico');
            $balanceColumn = $wallet === 'demo' ? 'balance_pico' : ($wallet === 'live' ? 'paid_balance_pico' : 'test_balance_pico');
            if ($amounts['maximum'] > $user->{$balanceColumn} - $reserved) {
                throw ValidationException::withMessages(['prompt' => 'Not enough available credits for the estimated maximum. Add credits or wait for a pending generation to finish.']);
            }
            if ($mode === 'demo') {
                app(DemoBudget::class)->reserve($amounts['maximum']);
            }
            $g = Generation::create($input + [
                'user_id' => $request->user()->id, 'client_request_id' => $requestId, 'request_hash' => $hash,
                'estimated_cost_pico' => $amounts['estimate'], 'minimum_cost_pico' => $amounts['minimum'], 'maximum_cost_pico' => $amounts['maximum'],
                'reserved_pico' => $amounts['maximum'],
                'provider' => $mode === 'real' ? 'foundry' : 'pixelforge', 'wallet' => $wallet,
                'rates' => $mode === 'real' ? app(FoundryImages::class)->rates() : null,
                'budget_date' => $mode === 'real' ? app(FoundryImages::class)->reserveBudget($amounts['maximum']) : null,
            ]);
            // With the database queue this insert shares the same database transaction.
            if ($mode === 'real') {
                GenerateFoundryImage::dispatch($g->id);
            } else {
                SubmitGeneration::dispatch($g->id);
            }
        }, 5);

        return redirect('/studio'.($mode === 'real' || $this->realEnabled($request->user()) ? '?mode='.$mode : ''))->with('message', 'Your generation is queued. We will update its status here.');
    }

    public function image(Request $request, Generation $generation)
    {
        abort_unless($generation->user_id === $request->user()->id && $generation->status === 'succeeded', 404);
        $asset = DB::table('generated_assets')->where('generation_id', $generation->id)->first();
        abort_unless($asset, 404);

        return response(base64_decode($asset->data_base64, true))->header('Content-Type', 'image/png')->header('X-Content-Type-Options', 'nosniff')
            ->header('Cache-Control', 'private, max-age=3600')->header('ETag', '"'.$asset->sha256.'"');
    }
}
