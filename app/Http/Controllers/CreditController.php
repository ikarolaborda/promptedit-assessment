<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CreditController extends Controller
{
    public function __invoke(Request $request)
    {
        $input = $request->validate(['request_id' => 'required|uuid']);
        DB::transaction(function () use ($request, $input) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            if (DB::table('credit_purchases')->where('user_id', $user->id)->where('request_id', $input['request_id'])->exists()) {
                return;
            }
            abort_if($user->balance_pico > 995000000000000, 422, 'The demo balance limit is $1,000.');
            DB::table('credit_purchases')->insert(['user_id' => $user->id, 'request_id' => $input['request_id'], 'amount_pico' => 5000000000000, 'created_at' => now(), 'updated_at' => now()]);
            $user->increment('balance_pico', 5000000000000);
        }, 5);

        return back()->with('message', '$5.00 demo credits added. No real payment was taken.');
    }
}
