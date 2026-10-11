<?php

namespace App\Support;

final class DisplayNumber
{
    public static function money(
        mixed $value,
        ?string $currency,
        int $fractionDigits = 3,
        bool $groupThousands = true,
    ): string {
        $number = (float) $value;
        $currency = strtoupper(trim((string) $currency));

        if ($currency !== 'EGP') {
            return number_format(
                $number,
                $fractionDigits,
                '.',
                $groupThousands ? ',' : '',
            );
        }

        return self::compact($number, $fractionDigits, $groupThousands);
    }

    public static function quantity(
        mixed $value,
        int $fractionDigits = 3,
        bool $preserveFixedPrecision = false,
        bool $groupThousands = true,
    ): string {
        $number = (float) $value;

        if ($preserveFixedPrecision) {
            return number_format(
                $number,
                $fractionDigits,
                '.',
                $groupThousands ? ',' : '',
            );
        }

        return self::compact($number, $fractionDigits, $groupThousands);
    }

    private static function compact(
        float $value,
        int $fractionDigits,
        bool $groupThousands,
    ): string {
        if ($fractionDigits < 0) {
            throw new \InvalidArgumentException('fractionDigits must be zero or greater.');
        }

        $formatted = number_format(
            $value,
            $fractionDigits,
            '.',
            $groupThousands ? ',' : '',
        );

        if ($fractionDigits === 0 || ! str_contains($formatted, '.')) {
            return $formatted;
        }

        return rtrim(rtrim($formatted, '0'), '.');
    }
}
