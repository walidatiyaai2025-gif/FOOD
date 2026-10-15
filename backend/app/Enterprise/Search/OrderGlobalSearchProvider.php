<?php

namespace App\Enterprise\Search;

use App\Models\User;
use App\Services\OperationalTenantScope;
use App\Support\DisplayNumber;
use Illuminate\Support\Facades\DB;

final class OrderGlobalSearchProvider implements GlobalSearchProvider
{
    public function __construct(private readonly OperationalTenantScope $scope) {}

    public function search(User $actor, string $query, int $limit): array
    {
        $storeIds = $this->scope->allowedStoreIds($actor, 'orders.view');
        if ($storeIds === []) {
            return [];
        }

        $displayId = DisplayNumber::referenceId($query);
        $rows = DB::table('orders')
            ->join('stores', 'stores.id', '=', 'orders.store_id')
            ->whereIn('orders.store_id', $storeIds)
            ->where(function ($builder) use ($query, $displayId): void {
                $builder->where('orders.order_number', 'like', '%'.$query.'%');
                if ($displayId !== null) {
                    $builder->orWhere('orders.id', $displayId);
                }
            })
            ->orderByDesc('orders.id')
            ->limit($limit)
            ->get([
                'orders.id',
                'orders.channel',
                'orders.store_id',
                'stores.name as store_name',
            ]);

        return $rows->map(static function (object $row): array {
            $channel = strtolower((string) $row->channel);
            $channelLabel = match ($channel) {
                'b2b' => __('global_search.channels.b2b'),
                'b2c' => __('global_search.channels.b2c'),
                default => __('global_search.channels.other'),
            };

            return [
                'type' => 'order',
                'type_label' => __('global_search.types.order'),
                'title' => DisplayNumber::reference((int) $row->id),
                'subtitle' => trim((string) $row->store_name).' · '.$channelLabel,
                'url' => route('admin.operations.orders.index', [
                    'channel' => $channel,
                    'store_id' => (int) $row->store_id,
                    'order' => (int) $row->id,
                ], false),
            ];
        })->values()->all();
    }
}
