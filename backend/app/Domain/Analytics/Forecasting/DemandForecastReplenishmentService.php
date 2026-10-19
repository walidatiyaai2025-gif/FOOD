<?php

namespace App\Domain\Analytics\Forecasting;

use App\Services\RetailInventoryIntelligenceService;
use App\Services\RetailReorderIntelligenceService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final readonly class DemandForecastReplenishmentService
{
    private const MAX_STORES = 25;

    public function __construct(
        private RetailInventoryIntelligenceService $inventory,
        private RetailReorderIntelligenceService $reorder,
        private DemandForecastEngine $engine,
    ) {}

    /**
     * @param  list<int>  $retailStoreIds
     * @return array<string,mixed>
     */
    public function forStores(
        array $retailStoreIds,
        int $horizonDays = DemandForecastEngine::DEFAULT_HORIZON_DAYS,
        ?CarbonImmutable $asOf = null,
    ): array {
        $storeIds = collect($retailStoreIds)
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($storeIds === [] || count($storeIds) !== count($retailStoreIds) || count($storeIds) > self::MAX_STORES) {
            throw ValidationException::withMessages([
                'retailer_store_ids' => ['Provide between 1 and 25 unique positive retailer store ids.'],
            ]);
        }

        sort($storeIds);
        $asOf ??= CarbonImmutable::now('Asia/Kuwait');

        $snapshots = [];
        foreach ($storeIds as $storeId) {
            $snapshots[] = [
                'retail_store_id' => $storeId,
                'inventory' => $this->inventory->forStore($storeId, $asOf),
                'reorder' => $this->reorder->forStore($storeId, $asOf),
            ];
        }

        $result = $this->engine->evaluate($snapshots, $horizonDays);
        $result['as_of'] = $asOf->setTimezone('Asia/Kuwait')->toIso8601String();
        $result['retailer_store_ids'] = $storeIds;

        return $result;
    }
}
