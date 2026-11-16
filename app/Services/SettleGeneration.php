<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;

class SettleGeneration
{
    /** Used by both polling and webhooks; one transaction owns final state and the unique charge. */
    public function apply(string $localId, array $remote): void
    {
        Validator::make($remote, [
            'id' => ['required', 'string', 'starts_with:gen_', 'max:255'],
            'status' => ['required', Rule::in(['queued', 'processing', 'succeeded', 'failed'])],
            'metadata.app_generation_id' => ['required', 'string', Rule::in([$localId])],
            'completed_at' => ['required_if:status,succeeded,failed', 'nullable', 'date'],
            'image_url' => ['nullable', 'url:http,https'],
            'image_url_expires_at' => ['nullable', 'date'],
        ])->validate();

        DB::transaction(function () use ($localId, $remote) {
            $owner = Generation::whereKey($localId)->value('user_id');
            $user = User::whereKey($owner)->lockForUpdate()->firstOrFail();
            $g = Generation::query()->lockForUpdate()->findOrFail($localId);
            if ($g->provider_id && $g->provider_id !== $remote['id']) {
                throw new RuntimeException('Provider identifier mismatch.');
            }
            if ($g->terminal()) {
                return; // Terminal states never regress, including a late POST response.
            }
            $changes = ['provider_id' => $remote['id'], 'last_synced_at' => now(), 'needs_attention' => false];
            if ($g->status === 'processing' && $remote['status'] === 'queued') {
                $g->update($changes);

                return;
            }
            $changes += ['status' => $remote['status'], 'error_code' => null, 'error_message' => null];
            if ($remote['status'] === 'succeeded') {
                $cost = Money::cost($remote['usage'] ?? [], $remote['rates'] ?? []);
                $charge = Money::markedUp($cost);
                Charge::create([
                    'generation_id' => $g->id, 'user_id' => $g->user_id,
                    'provider_cost_pico' => $cost, 'user_charge_pico' => $charge,
                    'completed_at' => $remote['completed_at'],
                ]);
                $user->decrement('balance_pico', $charge);
                $changes += [
                    'reserved_pico' => 0,
                    'usage' => $remote['usage'], 'rates' => $remote['rates'],
                    'image_url' => $remote['image_url'] ?? null,
                    'image_url_expires_at' => $remote['image_url_expires_at'] ?? null,
                    'completed_at' => $remote['completed_at'],
                ];
            } elseif ($remote['status'] === 'failed') {
                $changes = array_replace($changes, ['reserved_pico' => 0, 'completed_at' => $remote['completed_at'], 'error_code' => $remote['error']['code'] ?? 'generation_failed',
                    'error_message' => 'The provider could not complete this generation. You were not charged.']);
            }
            $g->update($changes);
        }, 5);
    }
}
