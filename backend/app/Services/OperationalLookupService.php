<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use stdClass;

final class OperationalLookupService
{
    public const PAYMENT_OPERATION_TYPE = 'payment_operation_type';

    public const PAYMENT_METHOD = 'payment_method';

    public const PRICE_TIER = 'price_tier';

    public const ORDER_STATUS = 'order_status';

    public const FAILED_DELIVERY_REASON = 'failed_delivery_reason';

    /** @var array<string,string> */
    private const PUBLIC_TYPES = [
        'payment-operation-types' => self::PAYMENT_OPERATION_TYPE,
        'payment-methods' => self::PAYMENT_METHOD,
        'pricing-tiers' => self::PRICE_TIER,
        'order-statuses' => self::ORDER_STATUS,
        'failed-delivery-reasons' => self::FAILED_DELIVERY_REASON,
    ];

    public function resolvePublicType(string $type): string
    {
        $resolved = self::PUBLIC_TYPES[$type] ?? null;

        if ($resolved === null) {
            throw new InvalidArgumentException('Unsupported operational lookup type.');
        }

        return $resolved;
    }

    /** @return list<string> */
    public function publicTypes(): array
    {
        return array_keys(self::PUBLIC_TYPES);
    }

    /** @return Collection<int, stdClass> */
    public function active(string $type): Collection
    {
        if ($type === self::PRICE_TIER) {
            if (! Schema::hasTable('b2b_price_tiers')) {
                return collect();
            }

            $query = DB::table('b2b_price_tiers')
                ->orderBy('priority')
                ->orderBy('id');

            if (Schema::hasColumn('b2b_price_tiers', 'is_active')) {
                $query->where('is_active', true);
            }

            return $query->get([
                'id',
                'code',
                DB::raw(Schema::hasColumn('b2b_price_tiers', 'name_ar') ? 'name_ar as label_ar' : 'name as label_ar'),
                DB::raw(Schema::hasColumn('b2b_price_tiers', 'name_en') ? 'name_en as label_en' : 'name as label_en'),
                DB::raw('priority as sort_order'),
            ]);
        }

        $this->assertOperationalType($type);

        if (! Schema::hasTable('operational_lookups')) {
            return collect();
        }

        return DB::table('operational_lookups')
            ->where('type', $type)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'code', 'label_ar', 'label_en', 'sort_order']);
    }

    /** @return list<string> */
    public function activeCodes(string $type): array
    {
        return $this->active($type)
            ->pluck('code')
            ->map(static fn ($code): string => (string) $code)
            ->values()
            ->all();
    }

    /** @return list<array{id:int,code:string,label:string,label_ar:string,label_en:string,sort_order:int}> */
    public function options(string $publicType, string $locale): array
    {
        $type = $this->resolvePublicType($publicType);

        return $this->active($type)
            ->map(static function (object $row) use ($locale): array {
                $labelAr = (string) ($row->label_ar ?? '');
                $labelEn = (string) ($row->label_en ?? '');

                return [
                    'id' => (int) $row->id,
                    'code' => (string) $row->code,
                    'label' => $locale === 'ar' ? $labelAr : $labelEn,
                    'label_ar' => $labelAr,
                    'label_en' => $labelEn,
                    'sort_order' => (int) $row->sort_order,
                ];
            })
            ->values()
            ->all();
    }

    private function assertOperationalType(string $type): void
    {
        if (! in_array($type, [
            self::PAYMENT_OPERATION_TYPE,
            self::PAYMENT_METHOD,
            self::ORDER_STATUS,
            self::FAILED_DELIVERY_REASON,
        ], true)) {
            throw new InvalidArgumentException('Unsupported operational lookup type.');
        }
    }
}
