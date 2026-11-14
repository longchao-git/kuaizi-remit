<?php

namespace Tests\Unit;

use App\Service\Remittance\QuoteCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class QuoteCalculatorTest extends TestCase
{
    public function test_channel_fee_uses_principal_and_discount_only_applies_to_base_fee(): void
    {
        $quote = (new QuoteCalculator)->calculate(100_000, 80_540, 599, 599, 80, 200_000, '2–10 分钟', '5–15 分钟', '通道费率 {费率}');

        $this->assertSame(800, $quote['channel_fee_minor']);
        $this->assertSame(100_800, $quote['total_payable_minor']);
        $this->assertSame(805_400, $quote['payout_minor']);
        $this->assertSame('2–10 分钟', $quote['eta']);
        $this->assertSame('通道费率 0.8%', $quote['payment_notice']);
    }

    public function test_half_cent_rounds_up_and_exact_threshold_uses_the_higher_band(): void
    {
        $quote = (new QuoteCalculator)->calculate(125, 80_540, 0, 0, 40, 125, '低档', '高档', '');

        $this->assertSame(1, $quote['channel_fee_minor']);
        $this->assertSame(1_007, $quote['payout_minor']);
        $this->assertSame('高档', $quote['eta']);
    }

    public function test_discount_cannot_exceed_base_fee(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new QuoteCalculator)->calculate(100, 80_540, 599, 600, 0, 200_000, '低档', '高档', '');
    }
}
