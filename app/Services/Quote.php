<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class Quote
{
    public function calculate(array $input, array $pricing): array
    {
        $tokens = $pricing['tokens'];
        $text = (int) ceil(mb_strlen($input['prompt']) / $tokens['characters_per_text_token']);
        $variance = BigDecimal::of((string) $tokens['text_token_variance']);
        $lowText = BigDecimal::of($text)->multipliedBy(BigDecimal::one()->minus($variance))->toScale(0, RoundingMode::Floor)->toInt();
        $highText = BigDecimal::of($text)->multipliedBy(BigDecimal::one()->plus($variance))->toScale(0, RoundingMode::Ceiling)->toInt();
        $reasoning = $tokens['reasoning'][$input['quality']];
        $fixed = [
            'input_image_tokens' => count($input['reference_images'] ?? []) * $tokens['per_reference_image'],
            'output_tokens' => $tokens['output'][$input['quality']][$input['size']],
        ];
        $price = fn (int $t, int $r) => Money::markedUp(Money::cost($fixed + ['input_text_tokens' => $t, 'reasoning_tokens' => $r], $pricing['rates']));

        return [
            'estimate' => $price($text, intdiv($reasoning['min'] + $reasoning['max'], 2)),
            'minimum' => $price($lowText, $reasoning['min']),
            'maximum' => $price($highText, $reasoning['max']),
        ];
    }
}
