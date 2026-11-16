<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/** Exact USD in picodollars: 12 decimal places, including the 25% markup. */
final class Money
{
    public const SCALE = 12;

    public const TOKEN_TYPES = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

    public static function parse(string $dollars): int
    {
        return BigDecimal::of($dollars)->toScale(self::SCALE, RoundingMode::Unnecessary)->getUnscaledValue()->toInt();
    }

    public static function format(int $pico): string
    {
        return (string) BigDecimal::ofUnscaledValue($pico, self::SCALE);
    }

    public static function cost(array $usage, array $rates): int
    {
        $total = BigDecimal::zero();
        foreach (self::TOKEN_TYPES as $type) {
            if (! isset($usage[$type], $rates[$type]) || ! is_int($usage[$type]) || $usage[$type] < 0 || ! is_string($rates[$type]) || ! preg_match('/^\d+(\.\d+)?$/D', $rates[$type])) {
                throw new InvalidArgumentException('Missing or invalid usage/rate data.');
            }
            $total = $total->plus(BigDecimal::of($rates[$type])->multipliedBy($usage[$type])->dividedBy(1000000, self::SCALE, RoundingMode::Unnecessary));
        }

        return $total->getUnscaledValue()->toInt();
    }

    public static function markedUp(int $providerPico): int
    {
        return BigDecimal::of($providerPico)->multipliedBy(5)->dividedBy(4, 0, RoundingMode::Unnecessary)->toInt();
    }
}
