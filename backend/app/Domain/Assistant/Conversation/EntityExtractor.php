<?php

namespace App\Domain\Assistant\Conversation;

final class EntityExtractor
{
    private TextNormalizer $normalizer;

    public function __construct(?TextNormalizer $normalizer = null)
    {
        $this->normalizer = $normalizer ?? new TextNormalizer;
    }

    /** @return array<string, int|string> */
    public function extract(string $originalMessage, string $normalizedMessage): array
    {
        $entities = [];

        $this->extractId($normalizedMessage, 'order_id', ['order', 'طلب'], $entities);
        $this->extractId($normalizedMessage, 'store_id', ['store', 'branch', 'متجر', 'فرع'], $entities);
        $this->extractId($normalizedMessage, 'customer_id', ['customer', 'عميل'], $entities);
        $this->extractId($normalizedMessage, 'driver_id', ['driver', 'سائق'], $entities);
        $this->extractId($normalizedMessage, 'product_id', ['product', 'منتج'], $entities);

        $channel = $this->firstAlias($normalizedMessage, [
            'B2B' => ['b2b', 'wholesale', 'جمله', 'الجمله'],
            'B2C' => ['b2c', 'retail', 'تجزئه', 'التجزئه'],
        ]);

        if ($channel !== null) {
            $entities['channel'] = $channel;
        }

        $status = $this->firstAlias($normalizedMessage, [
            'late' => ['late', 'delayed', 'متاخر', 'متاخره', 'متعطل', 'متعطله'],
            'cancelled' => ['cancelled', 'canceled', 'ملغي', 'ملغيه'],
            'pending' => ['pending', 'waiting', 'معلق', 'قيد الانتظار'],
            'completed' => ['completed', 'complete', 'delivered', 'مكتمل', 'تم التوصيل'],
        ]);

        if ($status !== null) {
            $entities['status'] = $status;
        }

        foreach ([
            'store_name' => ['store', 'branch', 'متجر', 'فرع'],
            'customer_name' => ['customer', 'عميل'],
            'driver_name' => ['driver', 'سائق'],
            'product_name' => ['product', 'منتج'],
        ] as $key => $aliases) {
            $name = $this->extractName($originalMessage, $aliases);

            if ($name !== null) {
                $entities[$key] = $name;
            }
        }

        return $entities;
    }

    /**
     * @param  list<string>  $aliases
     * @param  array<string, int|string>  $entities
     */
    private function extractId(string $message, string $key, array $aliases, array &$entities): void
    {
        $pattern = '/(?:'.implode('|', array_map(
            static fn (string $alias): string => preg_quote($alias, '/'),
            $aliases,
        )).')\s*(?:#|number|no|رقم)?\s*#?(\d{1,12})/u';

        if (preg_match($pattern, $message, $matches) === 1) {
            $entities[$key] = (int) $matches[1];
        }
    }

    /**
     * @param  array<string, list<string>>  $groups
     */
    private function firstAlias(string $message, array $groups): ?string
    {
        foreach ($groups as $canonical => $aliases) {
            foreach ($aliases as $alias) {
                $normalizedAlias = $this->normalizer->normalize($alias);

                if (str_contains(' '.$message.' ', ' '.$normalizedAlias.' ')) {
                    return $canonical;
                }
            }
        }

        return null;
    }

    /** @param list<string> $aliases */
    private function extractName(string $message, array $aliases): ?string
    {
        $aliasPattern = implode('|', array_map(
            static fn (string $alias): string => preg_quote($alias, '/'),
            $aliases,
        ));

        $patterns = [
            '/(?:'.$aliasPattern.')\s+(?:named|name|اسمه|اسم)\s+["“]?([\p{L}][\p{L}\p{M}\s._-]{1,60})["”]?/iu',
            '/(?:'.$aliasPattern.')\s+["“]([^"”]{2,60})["”]/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message, $matches) !== 1) {
                continue;
            }

            $name = preg_split(
                '/\s+(?:today|yesterday|this week|last week|اليوم|امس|هذا الاسبوع|الاسبوع الماضي|الاسبوع اللي فات)\b/iu',
                trim($matches[1]),
                2,
            )[0] ?? '';

            $name = trim($name, " \t\n\r\0\x0B\"'.,!?؟");

            if ($name !== '') {
                return $name;
            }
        }

        return null;
    }
}
