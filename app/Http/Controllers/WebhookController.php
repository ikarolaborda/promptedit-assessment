<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Services\SettleGeneration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WebhookController extends Controller
{
    public function __invoke(Request $request, SettleGeneration $settler)
    {
        $raw = $request->getContent();
        abort_if(strlen($raw) > 100000, 413);
        $secret = config('services.pixelforge.webhook_secret');
        abort_unless(is_string($secret) && strlen($secret) > 10, 503);
        $header = (string) $request->header('Pixelforge-Signature');
        abort_unless(preg_match('/^t=(\d+),v1=([a-f0-9]{64})$/D', $header, $match), 400, 'Invalid signature.');
        abort_unless(abs(time() - (int) $match[1]) <= 300 && hash_equals(hash_hmac('sha256', $match[1].'.'.$raw, $secret), $match[2]), 400, 'Invalid signature.');
        $event = json_decode($raw, true);
        abort_if(json_last_error() !== JSON_ERROR_NONE, 400, 'Invalid JSON.');
        abort_unless(is_array($event) && is_string($event['id'] ?? null) && strlen($event['id']) <= 255 && str_starts_with($event['id'], 'evt_'), 422);
        abort_unless(in_array($event['type'] ?? '', ['ping', 'generation.processing', 'generation.succeeded', 'generation.failed'], true), 422);

        DB::transaction(function () use ($event, $raw, $settler) {
            $inserted = DB::table('webhook_events')->insertOrIgnore([
                'id' => $event['id'], 'type' => $event['type'], 'payload_hash' => hash('sha256', $raw),
                'payload' => $raw, 'received_at' => now(),
            ]);
            if (! $inserted) {
                abort_unless(hash_equals(DB::table('webhook_events')->where('id', $event['id'])->value('payload_hash'), hash('sha256', $raw)), 409);

                return;
            }
            if ($event['type'] === 'ping') {
                return;
            }
            $remote = $event['data']['object'] ?? [];
            abort_unless('generation.'.($remote['status'] ?? '') === $event['type'], 422);
            $localId = $remote['metadata']['app_generation_id'] ?? null;
            // Unknown objects are acknowledged but never attached to another user's record.
            if ($localId && Generation::whereKey($localId)->exists()) {
                $settler->apply($localId, $remote);
            }
        }, 5);

        return response()->noContent();
    }
}
