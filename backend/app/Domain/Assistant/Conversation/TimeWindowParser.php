<?php

namespace App\Domain\Assistant\Conversation;

use Carbon\CarbonImmutable;

final class TimeWindowParser
{
    /**
     * @return array{key: string, start: string, end: string}|null
     */
    public function parse(string $normalizedMessage, ?CarbonImmutable $now = null): ?array
    {
        $now ??= CarbonImmutable::now();

        foreach ([
            'last_week' => ['last week', 'previous week', 'الاسبوع اللي فات', 'بالاسبوع اللي فات', 'الاسبوع الماضي', 'بالاسبوع الماضي', 'الاسبوع السابق'],
            'this_week' => ['this week', 'current week', 'هذا الاسبوع', 'الاسبوع الحالي'],
            'yesterday' => ['yesterday', 'امس'],
            'today' => ['today', 'اليوم'],
        ] as $key => $phrases) {
            foreach ($phrases as $phrase) {
                if (! str_contains(' '.$normalizedMessage.' ', ' '.$phrase.' ')) {
                    continue;
                }

                [$start, $end] = $this->bounds($key, $now);

                return [
                    'key' => $key,
                    'start' => $start->toIso8601String(),
                    'end' => $end->toIso8601String(),
                ];
            }
        }

        return null;
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function bounds(string $key, CarbonImmutable $now): array
    {
        return match ($key) {
            'today' => [$now->startOfDay(), $now->startOfDay()->addDay()],
            'yesterday' => [$now->startOfDay()->subDay(), $now->startOfDay()],
            'this_week' => [$now->startOfWeek(), $now->startOfWeek()->addWeek()],
            'last_week' => [$now->startOfWeek()->subWeek(), $now->startOfWeek()],
            default => [$now->startOfDay(), $now->startOfDay()->addDay()],
        };
    }
}
