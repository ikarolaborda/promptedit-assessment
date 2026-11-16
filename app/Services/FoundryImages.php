<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class FoundryImages
{
    public function rates(): array
    {
        return ['input_text_tokens' => (string) config('services.foundry.text_rate'), 'input_image_tokens' => (string) config('services.foundry.image_rate'),
            'output_tokens' => (string) config('services.foundry.output_rate'), 'reasoning_tokens' => '0'];
    }

    public function quote(array $input): array
    {
        $wide = $input['size'] !== '1024x1024';
        $output = ['low' => 600, 'medium' => 1400, 'high' => 4500][$input['quality']];
        $maximum = ['low' => 1024, 'medium' => 3072, 'high' => 8192][$input['quality']];
        $price = fn (int $text, int $out) => Money::markedUp(Money::cost(['input_text_tokens' => $text, 'input_image_tokens' => 0, 'output_tokens' => $out, 'reasoning_tokens' => 0], $this->rates()));

        return ['estimate' => $price((int) ceil(mb_strlen($input['prompt']) / 4) + 16, $wide ? intdiv($output * 3, 2) : $output),
            'minimum' => 0, 'maximum' => $price(strlen($input['prompt']) + 512, $wide ? intdiv($maximum * 3, 2) : $maximum)];
    }

    public function reserveBudget(int $amount): string
    {
        $date = now()->utc()->toDateString();
        DB::table('image_budgets')->insertOrIgnore(['date' => $date]);
        $budget = DB::table('image_budgets')->where('date', $date)->lockForUpdate()->first();
        if ($budget->spent_pico + $budget->reserved_pico + $amount > Money::parse((string) config('services.foundry.daily_budget'))) {
            throw ValidationException::withMessages(['prompt' => 'Image generation has reached today’s service limit. Please try again tomorrow.']);
        }
        DB::table('image_budgets')->where('date', $date)->increment('reserved_pico', $amount);

        return $date;
    }

    public function generate(Generation $g): Response
    {
        return Http::acceptJson()->asJson()->withHeaders(['api-key' => config('services.foundry.key'), 'x-ms-client-request-id' => $g->id])
            ->connectTimeout(10)->timeout(150)->post(rtrim(config('services.foundry.endpoint'), '/').'/openai/v1/images/generations', [
                'model' => config('services.foundry.deployment'), 'prompt' => $g->prompt, 'n' => 1,
                'size' => $g->size, 'quality' => $g->quality, 'output_format' => 'png', 'user' => hash('sha256', 'frame-user:'.$g->user_id),
            ]);
    }

    public function settle(Generation $g, array $response): void
    {
        $usage = $response['usage'] ?? [];
        $text = $usage['input_tokens_details']['text_tokens'] ?? null;
        $image = $usage['input_tokens_details']['image_tokens'] ?? null;
        $output = $usage['output_tokens'] ?? null;
        foreach ([$text, $image, $output] as $count) {
            if (! is_int($count) || $count < 0) {
                throw new RuntimeException('Missing image billing usage.');
            }
        }
        if ($image !== 0 || ($usage['input_tokens'] ?? null) !== $text || ! empty($usage['input_tokens_details']['cached_tokens'])) {
            throw new RuntimeException('Unexpected image input usage; operator reconciliation required.');
        }
        $normalized = ['input_text_tokens' => $text, 'input_image_tokens' => $image, 'output_tokens' => $output, 'reasoning_tokens' => 0];
        $cost = Money::cost($normalized, $g->rates);
        $encoded = $response['data'][0]['b64_json'] ?? '';
        if (! is_string($encoded) || strlen($encoded) > 12 * 1024 * 1024) {
            throw new RuntimeException('Invalid image result.');
        }
        $bytes = base64_decode($encoded, true);
        if (! $bytes || ! str_starts_with($bytes, "\x89PNG\r\n\x1a\n") || ! @getimagesizefromstring($bytes)) {
            throw new RuntimeException('Invalid PNG image.');
        }
        DB::transaction(function () use ($g, $normalized, $cost, $encoded, $bytes) {
            $user = User::whereKey($g->user_id)->lockForUpdate()->firstOrFail();
            $locked = Generation::whereKey($g->id)->lockForUpdate()->firstOrFail();
            if ($locked->terminal()) {
                return;
            }
            $charged = min(Money::markedUp($cost), $locked->maximum_cost_pico);
            Charge::create(['generation_id' => $g->id, 'user_id' => $g->user_id, 'provider_cost_pico' => $cost, 'user_charge_pico' => $charged, 'completed_at' => now()]);
            $user->decrement($locked->wallet === 'live' ? 'paid_balance_pico' : 'test_balance_pico', $charged);
            DB::table('generated_assets')->insert(['generation_id' => $g->id, 'data_base64' => $encoded, 'sha256' => hash('sha256', $bytes), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('image_budgets')->where('date', $locked->budget_date)->decrement('reserved_pico', $locked->reserved_pico);
            DB::table('image_budgets')->where('date', $locked->budget_date)->increment('spent_pico', $cost);
            $locked->update(['status' => 'succeeded', 'reserved_pico' => 0, 'usage' => $normalized, 'image_url' => '/generations/'.$g->id.'/image',
                'completed_at' => now(), 'needs_attention' => false, 'error_message' => null, 'error_code' => null]);
        }, 5);
    }

    public function fail(Generation $g, string $message): void
    {
        DB::transaction(function () use ($g, $message) {
            User::whereKey($g->user_id)->lockForUpdate()->firstOrFail();
            $locked = Generation::whereKey($g->id)->lockForUpdate()->firstOrFail();
            if ($locked->terminal()) {
                return;
            }
            DB::table('image_budgets')->where('date', $locked->budget_date)->decrement('reserved_pico', $locked->reserved_pico);
            $locked->update(['status' => 'failed', 'reserved_pico' => 0, 'completed_at' => now(), 'error_message' => $message, 'error_code' => 'provider_declined']);
        }, 5);
    }
}
