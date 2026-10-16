<?php

namespace App\Domain\SmartOrdering;

use Illuminate\Validation\ValidationException;

final class SmartOrderingAvailabilitySchedule
{
    private const MAX_WINDOWS = 28;

    /**
     * @return array{windows:list<array{days:list<int>,start:string,end:string}>}|null
     */
    public function normalize(mixed $schedule): ?array
    {
        if ($schedule === null || $schedule === [] || $schedule === '') {
            return null;
        }

        if (! is_array($schedule)) {
            $this->fail();
        }

        $windows = array_is_list($schedule)
            ? $schedule
            : ($schedule['windows'] ?? null);

        if (! is_array($windows)) {
            $this->fail();
        }
        if ($windows === []) {
            return null;
        }
        if (count($windows) > self::MAX_WINDOWS) {
            $this->fail();
        }

        $normalized = [];

        foreach ($windows as $window) {
            if (! is_array($window)) {
                $this->fail();
            }

            $days = $this->days($window['days'] ?? null);
            $start = $this->time($window['start'] ?? null);
            $end = $this->time($window['end'] ?? null);
            $key = implode(',', $days).'|'.$start.'|'.$end;

            $normalized[$key] = [
                'days' => $days,
                'start' => $start,
                'end' => $end,
            ];
        }

        $windows = array_values($normalized);
        usort($windows, static function (array $left, array $right): int {
            return [$left['start'], $left['end'], implode(',', $left['days'])]
                <=> [$right['start'], $right['end'], implode(',', $right['days'])];
        });

        return ['windows' => $windows];
    }

    /** @return list<int> */
    private function days(mixed $value): array
    {
        if (! is_array($value) || $value === []) {
            $this->fail();
        }

        $days = [];

        foreach ($value as $day) {
            if (is_int($day)) {
                $normalized = $day;
            } elseif (is_string($day) && ctype_digit($day)) {
                $normalized = (int) $day;
            } else {
                $this->fail();
            }

            if ($normalized < 1 || $normalized > 7) {
                $this->fail();
            }

            $days[] = $normalized;
        }

        $days = array_values(array_unique($days));
        sort($days, SORT_NUMERIC);

        return $days;
    }

    private function time(mixed $value): string
    {
        if (! is_string($value) || preg_match('/^(\d{2}):(\d{2})$/', $value, $matches) !== 1) {
            $this->fail();
        }

        $hour = (int) $matches[1];
        $minute = (int) $matches[2];

        if ($hour > 23 || $minute > 59) {
            $this->fail();
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    private function fail(): never
    {
        throw ValidationException::withMessages([
            'availability_schedule' => [__('smart_ordering_menu.validation.json')],
        ]);
    }
}
