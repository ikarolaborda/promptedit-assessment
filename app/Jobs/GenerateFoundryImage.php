<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Services\FoundryImages;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateFoundryImage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public bool $failOnTimeout = true;

    public function __construct(public string $generationId) {}

    public function handle(FoundryImages $api): void
    {
        $g = Generation::find($this->generationId);
        if (! $g || $g->provider !== 'foundry' || $g->status !== 'queued') {
            return;
        }
        if (! config('services.foundry.enabled')) {
            $api->fail($g, 'Real image generation is unavailable. You were not charged.');

            return;
        }
        // Claim before any network call. Azure image creation has no documented retry idempotency.
        if (! Generation::whereKey($g->id)->where('status', 'queued')->update(['status' => 'processing', 'submit_attempts' => 1])) {
            return;
        }
        try {
            $response = $api->generate($g);
            if ($response->successful()) {
                $api->settle($g, $response->json());

                return;
            }
            if ($response->clientError()) {
                $api->fail($g, $response->status() === 429 ? 'The image provider is busy. You were not charged; please try again shortly.' : 'The image provider declined this request. You were not charged.');

                return;
            }
            $this->attention($g);
        } catch (Throwable $e) {
            Log::warning('Image request needs reconciliation', ['generation_id' => $g->id, 'exception' => $e::class]);
            $this->attention($g);
        }
    }

    private function attention(Generation $g): void
    {
        Generation::whereKey($g->id)->where('status', 'processing')->update(['needs_attention' => true, 'submission_uncertain' => true,
            'error_message' => 'Provider confirmation is pending. Your credits remain reserved and no charge has been recorded. Please contact support.']);
    }

    public function failed(?Throwable $exception): void
    {
        $g = Generation::find($this->generationId);
        if ($g) {
            $this->attention($g);
        }
    }
}
