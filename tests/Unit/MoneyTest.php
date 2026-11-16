<?php

namespace Tests\Unit;

use App\Services\Money;
use App\Services\Quote;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public static function pricing(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../Fixtures/pricing.json'), true);
    }

    public function test_exact_provider_cost_and_markup(): void
    {
        $usage = ['input_text_tokens' => 12, 'input_image_tokens' => 765, 'output_tokens' => 6450, 'reasoning_tokens' => 3184];
        $cost = Money::cost($usage, self::pricing()['rates']);
        $this->assertSame('0.305510000000', Money::format($cost));
        $this->assertSame('0.381887500000', Money::format(Money::markedUp($cost)));
    }

    public function test_sub_cent_precision_is_not_rounded(): void
    {
        $usage = ['input_text_tokens' => 1, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0];
        $rates = array_fill_keys(Money::TOKEN_TYPES, '0.001');
        $this->assertSame('0.000000001250', Money::format(Money::markedUp(Money::cost($usage, $rates))));
        $this->assertSame('123.000000000001', Money::format(Money::parse('123.000000000001')));
    }

    public function test_documented_maximum_quote(): void
    {
        $quote = (new Quote)->calculate(['prompt' => str_repeat('a', 400), 'size' => '1536x1024', 'quality' => 'high', 'reference_images' => ['https://example.com/a', 'https://example.com/b']], self::pricing());
        $this->assertSame('0.467343750000', Money::format($quote['maximum']));
        $this->assertLessThan($quote['maximum'], $quote['estimate']);
    }

    public function test_unicode_is_counted_as_characters(): void
    {
        $input = ['prompt' => '🌿🌿🌿🌿', 'size' => '1024x1024', 'quality' => 'low', 'reference_images' => []];
        $other = $input;
        $other['prompt'] = 'abcd';
        $this->assertSame((new Quote)->calculate($other, self::pricing()), (new Quote)->calculate($input, self::pricing()));
    }

    public function test_missing_token_bucket_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::cost(['output_tokens' => 42], self::pricing()['rates']);
    }

    public function test_negative_usage_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::cost(array_fill_keys(Money::TOKEN_TYPES, -1), self::pricing()['rates']);
    }
}
