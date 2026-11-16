<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Services\Pixelforge;
use App\Services\SettleGeneration;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    public int $timeout = 30;

    public function __construct(public string $generationId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('submit:'.$this->generationId))->releaseAfter(10)->expireAfter(45)];
    }

    public function handle(Pixelforge $api, SettleGeneration $settler): void
    {
        $g = Generation::find($this->generationId);
        if (! $g || $g->provider !== 'pixelforge' || $g->terminal() || $g->provider_id || $g->needs_attention || $g->next_attempt_at?->isFuture()) {
            return;
        }
        $g->increment('submit_attempts');
        try {
            $response = $api->submit($g);
        } catch (ConnectionException) {
            $this->retryLater($g, 'connection_uncertain', 'The connection was interrupted. Confirming the request safely.', true, 10);

            return;
        }
        if ($response->successful()) {
            $settler->apply($g->id, $response->json());
            PollGeneration::dispatch($g->id)->delay(now()->addSeconds(15));

            return;
        }
        $code = $response->json('error.code', 'provider_error');
        if (in_array($response->status(), [429, 503]) && $code !== 'daily_limit_exceeded') {
            $this->retryLater($g, $code, 'The provider is busy. Retrying automatically; no duplicate charge.', false, max(2, (int) $response->header('Retry-After')));

            return;
        }
        if ($response->serverError()) {
            $this->retryLater($g, $code, 'Waiting for the provider to confirm your generation.', true, 10);

            return;
        }
        if ($g->submission_uncertain) {
            Generation::whereKey($g->id)->whereNull('provider_id')->where('status', 'queued')->update(['needs_attention' => true, 'error_message' => 'Provider confirmation is pending. No charge has been recorded. Please contact the operator.']);

            return;
        }
        $message = match ($code) {
            'content_policy_violation' => 'The provider declined this prompt. Try a different description. You were not charged.',
            'daily_limit_exceeded' => 'The demo provider has reached its daily limit. Please try again tomorrow. You were not charged.',
            'invalid_api_key' => 'The provider connection needs attention. Please try again later. You were not charged.',
            default => 'The provider could not accept this request. You were not charged.',
        };
        Generation::whereKey($g->id)->whereNull('provider_id')->where('status', 'queued')
            ->update(['reserved_pico' => 0, 'status' => 'failed', 'completed_at' => now(), 'error_code' => $code, 'error_message' => $message]);
    }

    private function retryLater(Generation $g, string $code, string $message, bool $uncertain, int $after): void
    {
        $g->refresh();
        if ($g->terminal() || $g->provider_id) {
            return;
        }
        $uncertain = $uncertain || $g->submission_uncertain;
        $delay = max($after, min(120, 2 ** $g->submit_attempts));
        if ($g->submit_attempts >= 6) {
            Generation::whereKey($g->id)->whereNull('provider_id')->where('status', 'queued')->update([
                'submission_uncertain' => $uncertain,
                'status' => $uncertain ? 'queued' : 'failed',
                'completed_at' => $uncertain ? null : now(),
                'reserved_pico' => $uncertain ? $g->reserved_pico : 0,
                'needs_attention' => $uncertain,
                'error_code' => $code,
                'error_message' => $uncertain
                    ? 'Provider confirmation is pending after repeated network errors. No charge has been recorded. Please contact the operator.'
                    : 'The provider remained unavailable after six attempts. You were not charged. Please try again later.',
            ]);

            return;
        }
        Generation::whereKey($g->id)->whereNull('provider_id')->where('status', 'queued')->update(['submission_uncertain' => $uncertain, 'error_code' => $code, 'error_message' => $message, 'next_attempt_at' => now()->addSeconds($delay)]);
        self::dispatch($g->id)->delay(now()->addSeconds($delay));
    }
}
