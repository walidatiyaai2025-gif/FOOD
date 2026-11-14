<?php

namespace Tests\Unit\Support;

use App\Support\DisplayNumber;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DisplayNumberTest extends TestCase
{
    public function test_egp_money_uses_exactly_two_decimal_places(): void
    {
        self::assertSame('100.00', DisplayNumber::money(100.000, 'EGP'));
        self::assertSame('100.50', DisplayNumber::money(100.500, 'egp'));
        self::assertSame('100.50', DisplayNumber::money(100.50, 'EGP'));
        self::assertSame('100.57', DisplayNumber::money(100.567, ' EGP '));
    }

    public function test_non_egp_money_preserves_fixed_precision(): void
    {
        self::assertSame('100.000', DisplayNumber::money(100, 'KWD'));
        self::assertSame('100.500', DisplayNumber::money(100.5, 'USD'));
    }

    public function test_money_input_contract_uses_currency_precision(): void
    {
        self::assertSame(2, DisplayNumber::moneyFractionDigits('EGP'));
        self::assertSame('0.01', DisplayNumber::moneyStep('egp'));
        self::assertSame(3, DisplayNumber::moneyFractionDigits('KWD'));
        self::assertSame('0.001', DisplayNumber::moneyStep('KWD'));
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

    public function test_document_reference_uses_short_numeric_id(): void
    {
        self::assertSame('#1234', DisplayNumber::reference(1234));
        self::assertSame('#77', DisplayNumber::reference('077'));
        self::assertSame('—', DisplayNumber::reference(null));
        self::assertSame('—', DisplayNumber::reference('FDX-20261011-ABC'));
    }

    public function test_reference_id_accepts_plain_or_hash_prefixed_numbers(): void
    {
        self::assertSame(1234, DisplayNumber::referenceId('1234'));
        self::assertSame(1234, DisplayNumber::referenceId('#1234'));
        self::assertNull(DisplayNumber::referenceId('FDX-20261011-ABC'));
        self::assertNull(DisplayNumber::referenceId('0'));
    }

    public function test_numeric_reference_never_saturates_at_php_int_max(): void
    {
        $maximum = (string) PHP_INT_MAX;
        $justAboveMaximum = substr($maximum, 0, -1).((int) substr($maximum, -1) + 1);

        self::assertSame(PHP_INT_MAX, DisplayNumber::referenceId($maximum));
        self::assertSame(PHP_INT_MAX, DisplayNumber::referenceId('#000'.$maximum));
        self::assertSame('#'.$maximum, DisplayNumber::reference($maximum));

        self::assertNull(DisplayNumber::referenceId($justAboveMaximum));
        self::assertNull(DisplayNumber::referenceId('#'.$justAboveMaximum));
        self::assertNull(DisplayNumber::referenceId(str_repeat('9', 128)));
        self::assertSame('—', DisplayNumber::reference($justAboveMaximum));
        self::assertSame('—', DisplayNumber::reference(str_repeat('9', 128)));
    }

    public function test_numeric_reference_preserves_valid_padding_and_rejects_malformed_values(): void
    {
        self::assertSame(42, DisplayNumber::referenceId('  #00042  '));
        self::assertSame(42, DisplayNumber::referenceId('00042'));
        self::assertSame('#42', DisplayNumber::reference('00042'));

        foreach (['', '#', '0', '#000', '0000', '-42', '#-42', '##42', '# 42', '42.0', '42x'] as $invalid) {
            self::assertNull(DisplayNumber::referenceId($invalid));
        }

        self::assertSame('—', DisplayNumber::reference('0000'));
        self::assertSame('—', DisplayNumber::reference('-42'));
    }

    public function test_invalid_precision_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DisplayNumber::quantity(1, -1);
    }
}
