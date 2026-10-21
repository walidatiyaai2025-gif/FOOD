<?php

namespace App\Services;

final class LegacyStockLocationResolver
{
    /**
     * These transaction types prefer a van/car namespace when the numeric
     * StoreId exists in both wh_Stores and wh_Cars.
     */
    public const CAR_PREFERRED_TYPES = [13, 14, 19, 20];

    public static function key(string $sourceTable, int $sourceId): string
    {
        return $sourceTable.':'.$sourceId;
    }

    /**
     * Resolve wh_TransHeader.StoreId without collapsing wh_Stores and wh_Cars
     * into the same numeric namespace.
     *
     * Preferred namespace is selected by transaction semantics. If that
     * namespace does not contain the ID but the alternate namespace does,
     * fall back to the actual existing source row. When both contain the same
     * numeric ID, transaction semantics break the collision deterministically.
     */
    public static function resolve(int $transType, int $sourceId, array $locations): array
    {
        if ($sourceId <= 0) {
            return [
                'resolved' => false,
                'source_table' => null,
                'source_id' => $sourceId,
                'location_key' => null,
                'resolution' => 'invalid_source_id',
            ];
        }

        $preferredTable = in_array($transType, self::CAR_PREFERRED_TYPES, true)
            ? 'wh_Cars'
            : 'wh_Stores';
        $alternateTable = $preferredTable === 'wh_Cars'
            ? 'wh_Stores'
            : 'wh_Cars';

        $preferredKey = self::key($preferredTable, $sourceId);
        $alternateKey = self::key($alternateTable, $sourceId);

        $preferredExists = isset($locations[$preferredKey]);
        $alternateExists = isset($locations[$alternateKey]);

        if ($preferredExists) {
            return [
                'resolved' => true,
                'source_table' => $preferredTable,
                'source_id' => $sourceId,
                'location_key' => $preferredKey,
                'resolution' => $alternateExists
                    ? 'preferred_collision'
                    : 'preferred',
            ];
        }

        if ($alternateExists) {
            return [
                'resolved' => true,
                'source_table' => $alternateTable,
                'source_id' => $sourceId,
                'location_key' => $alternateKey,
                'resolution' => 'alternate_existing_fallback',
            ];
        }

        return [
            'resolved' => false,
            'source_table' => null,
            'source_id' => $sourceId,
            'location_key' => null,
            'resolution' => 'not_found_in_store_or_car',
        ];
    }
}
