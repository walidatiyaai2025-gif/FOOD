<?php

namespace Tests\Unit\Support;

use App\Support\DisplayNumber;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DisplayNumberTest extends TestCase
{
    public function test_egp_money_omits_redundant_trailing_zeroes(): void
    {
        self::assertSame('100', DisplayNumber::money(100.000, 'EGP'));
        self::assertSame('100.5', DisplayNumber::money(100.500, 'egp'));
        self::assertSame('100.125', DisplayNumber::money(100.125, ' EGP '));
    }

    public function test_non_egp_money_preserves_fixed_precision(): void
    {
        self::assertSame('100.000', DisplayNumber::money(100, 'KWD'));
        self::assertSame('100.500', DisplayNumber::money(100.5, 'USD'));
    }

    public function test_unit_counts_are_compact_but_fractional_values_are_preserved(): void
    {
        self::assertSame('12', DisplayNumber::quantity(12.000));
        self::assertSame('12.5', DisplayNumber::quantity(12.500));
        self::assertSame('12.125', DisplayNumber::quantity(12.125));
    }

    public function test_measurement_display_can_preserve_fixed_precision(): void
    {
        self::assertSame(
            '12.500',
            DisplayNumber::quantity(12.5, preserveFixedPrecision: true),
        );
    }

    public function test_invalid_precision_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DisplayNumber::quantity(1, -1);
    }
}
