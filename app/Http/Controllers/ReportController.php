<?php

namespace App\Http\Controllers;

use App\Models\Charge;
use App\Services\Money;
use App\Services\Pixelforge;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ReportController extends Controller
{
    public function __invoke(Request $request, Pixelforge $api)
    {
        abort_unless($request->user()->is_admin, 403);
        $dates = $request->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from']);
        $from = CarbonImmutable::parse($dates['from'] ?? now()->subDays(6)->toDateString(), 'UTC')->startOfDay();
        $to = CarbonImmutable::parse($dates['to'] ?? now()->toDateString(), 'UTC')->startOfDay();
        abort_if($from->gt($to) || $from->diffInDays($to) > 91, 422, 'Choose up to 92 UTC days.');
        $charges = Charge::where('completed_at', '>=', $from)->where('completed_at', '<', $to->addDay())->get();
        $remote = [];
        $providerError = null;
        try {
            $remote = collect($api->usage($from->toDateString(), $to->toDateString())['data'])->keyBy('date')->all();
        } catch (\Throwable $e) {
            report($e);
            $providerError = 'Provider usage is temporarily unavailable. Local totals remain available; reconciliation is not confirmed.';
        }
        $daily = [];
        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $date = $day->toDateString();
            $items = $charges->filter(fn ($c) => $c->completed_at->utc()->toDateString() === $date);
            $provider = (int) $items->sum('provider_cost_pico');
            $billed = (int) $items->sum('user_charge_pico');
            $reported = isset($remote[$date]) ? Money::parse($remote[$date]['cost_usd']) : null;
            $daily[] = ['date' => $date, 'count' => $items->count(), 'provider' => Money::format($provider),
                'charged' => Money::format($billed), 'profit' => Money::format($billed - $provider),
                'reported' => $reported === null ? null : Money::format($reported),
                'difference' => $reported === null ? null : Money::format($provider - $reported),
                'matches' => $reported === null ? null : $reported === $provider && (int) $remote[$date]['generations'] === $items->count(),
            ];
        }
        $users = $charges->groupBy('user_id')->map(function ($items, $id) {
            $provider = (int) $items->sum('provider_cost_pico');
            $billed = (int) $items->sum('user_charge_pico');

            return ['name' => 'User #'.$id, 'count' => $items->count(), 'provider' => Money::format($provider), 'charged' => Money::format($billed), 'profit' => Money::format($billed - $provider)];
        })->values();

        return Inertia::render('Report', ['daily' => $daily, 'users' => $users, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'providerError' => $providerError,
            'eventCount' => DB::table('webhook_events')->count(),
        ]);
    }
}
