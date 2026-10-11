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
        if ($fractionDigits < 0) {
            throw new \InvalidArgumentException('fractionDigits must be zero or greater.');
        }

        $number = (float) $value;
        $currency = strtoupper(trim((string) $currency));
        $separator = $groupThousands ? ',' : '';

        if ($currency === 'EGP') {
            return number_format($number, 2, '.', $separator);
        }

        return number_format($number, $fractionDigits, '.', $separator);
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

    public static function reference(mixed $id): string
    {
        if (is_int($id) && $id > 0) {
            return '#'.$id;
        }

        $raw = trim((string) $id);
        if ($raw !== '' && ctype_digit($raw) && (int) $raw > 0) {
            return '#'.(int) $raw;
        }

        return '—';
    }

    public static function referenceId(mixed $value): ?int
    {
        $raw = trim((string) $value);
        if (str_starts_with($raw, '#')) {
            $raw = substr($raw, 1);
        }

        if ($raw === '' || ! ctype_digit($raw)) {
            return null;
        }

        $id = (int) $raw;

        return $id > 0 ? $id : null;
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
