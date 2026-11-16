<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Services\Pixelforge;
use App\Services\SettleGeneration;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class PollGeneration implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public function __construct(public string $generationId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('poll:'.$this->generationId))->dontRelease()->expireAfter(45)];
    }

    public function handle(Pixelforge $api, SettleGeneration $settler): void
    {
        $g = Generation::find($this->generationId);
        if (! $g || $g->terminal() || ! $g->provider_id || $g->last_synced_at?->gt(now()->subSeconds(10))) {
            return;
        }
        if ($g->created_at->lt(now()->subHours(24))) {
            $g->update(['needs_attention' => true, 'error_message' => 'Provider confirmation is taking longer than expected. No charge has been recorded. Please contact the operator.']);

            return;
        }
        $delay = 20;
        try {
            $response = $api->retrieve($g->provider_id);
            if ($response->successful()) {
                $settler->apply($g->id, $response->json());
            } else {
                $delay = max(30, (int) $response->header('Retry-After'));
            }
        } catch (ConnectionException) {
            $delay = 30;
        }
        if (! $g->fresh()->terminal()) {
            self::dispatch($g->id)->delay(now()->addSeconds($delay));
        }
    }
}
