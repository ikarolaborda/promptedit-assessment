<?php

namespace App\Services;

use App\Models\Generation;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class Pixelforge
{
    private function client(): PendingRequest
    {
        return Http::baseUrl(config('services.pixelforge.url'))->acceptJson()->asJson()->connectTimeout(5)->timeout(20);
    }

    public function pricing(): array
    {
        return Cache::remember('pixelforge.pricing.v1', 60, fn () => $this->client()->get('/pricing')->throw()->json());
    }

    public function submit(Generation $g): Response
    {
        return $this->client()->withToken(config('services.pixelforge.key'))
            ->withHeaders(['Idempotency-Key' => 'generation:'.$g->id])
            ->post('/generations', [
                'prompt' => $g->prompt, 'size' => $g->size, 'quality' => $g->quality,
                'reference_images' => $g->reference_images,
                'metadata' => ['app_generation_id' => $g->id],
            ]);
    }

    public function retrieve(string $id): Response
    {
        return $this->client()->withToken(config('services.pixelforge.key'))->get('/generations/'.rawurlencode($id));
    }

    public function usage(string $from, string $to): array
    {
        return $this->client()->withToken(config('services.pixelforge.key'))->get('/usage', ['start_date' => $from, 'end_date' => $to])->throw()->json();
    }
}
