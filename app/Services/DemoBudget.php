<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DemoBudget
{
    /** Reserve the quoted maximum across all accounts. Failures keep the reservation until tomorrow. */
    public function reserve(int $maximum): void
    {
        $date = now()->utc()->toDateString();
        DB::table('demo_provider_budgets')->insertOrIgnore(['date' => $date]);
        $budget = DB::table('demo_provider_budgets')->where('date', $date)->lockForUpdate()->first();
        $limit = Money::parse((string) config('services.pixelforge.daily_budget'));
        if ($maximum <= 0 || $budget->committed_pico + $maximum > $limit) {
            throw ValidationException::withMessages(['prompt' => 'The demo provider has reached today’s service limit. Please try again tomorrow.']);
        }

        DB::table('demo_provider_budgets')->where('date', $date)->increment('committed_pico', $maximum);
    }
}
