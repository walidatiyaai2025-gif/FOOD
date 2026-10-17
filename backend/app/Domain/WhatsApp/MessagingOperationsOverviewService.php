<?php

namespace App\Domain\WhatsApp;

use App\Models\User;
use App\Services\OperationalTenantScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class MessagingOperationsOverviewService
{
    public function __construct(
        private readonly CampaignReportService $reports,
        private readonly OperationalTenantScope $scope,
    ) {}

    /** @return array<string,mixed> */
    public function overview(User $actor): array
    {
        $campaignIds = $this->visibleWhatsAppCampaignIds($actor);
        $campaignStatuses = $this->countsByStatus('whats_app_campaigns', $campaignIds);

        $recipientBase = DB::table('whats_app_campaign_recipients')
            ->whereIn('campaign_id', $campaignIds);

        $metrics = [
            'campaigns' => count($campaignIds),
            'recipients' => (clone $recipientBase)->count(),
            'eligible' => (clone $recipientBase)->where('eligibility_status', 'eligible')->count(),
            'suppressed' => (clone $recipientBase)->where('eligibility_status', 'suppressed')->count(),
            'sent' => (clone $recipientBase)->whereNotNull('sent_at')->count(),
            'delivered' => (clone $recipientBase)->whereNotNull('delivered_at')->count(),
            'read' => (clone $recipientBase)->whereNotNull('read_at')->count(),
            'replied' => (clone $recipientBase)->whereNotNull('replied_at')->count(),
            'failed' => (clone $recipientBase)->whereNotNull('failed_at')->count(),
        ];

        $suppressionReasons = DB::table('whats_app_campaign_recipients')
            ->whereIn('campaign_id', $campaignIds)
            ->where('eligibility_status', 'suppressed')
            ->selectRaw("COALESCE(suppression_reason, 'unknown') as reason, COUNT(*) as total")
            ->groupBy('suppression_reason')
            ->orderByDesc('total')
            ->limit(8)
            ->pluck('total', 'reason')
            ->map(static fn ($value): int => (int) $value)
            ->all();

        $failureReasons = DB::table('whats_app_campaign_recipients as r')
            ->leftJoin('whats_app_outbound_dispatches as d', 'd.message_id', '=', 'r.message_id')
            ->whereIn('r.campaign_id', $campaignIds)
            ->where(function ($query): void {
                $query->whereNotNull('r.failed_at')
                    ->orWhere('r.send_status', 'failed')
                    ->orWhereNotNull('d.last_error');
            })
            ->selectRaw("COALESCE(d.last_error, r.suppression_reason, r.send_status, 'unknown') as reason, COUNT(DISTINCT r.id) as total")
            ->groupBy('reason')
            ->orderByDesc('total')
            ->limit(8)
            ->pluck('total', 'reason')
            ->map(static fn ($value): int => (int) $value)
            ->all();

        $storeIds = $this->scope->allowedStoreIds($actor, 'whatsapp.campaigns.view');
        $consent = DB::table('whats_app_marketing_preferences as mp')
            ->join('platform_customers as pc', 'pc.id', '=', 'mp.platform_customer_id')
            ->whereIn('pc.origin_store_id', $storeIds)
            ->selectRaw('mp.state, COUNT(*) as total')
            ->groupBy('mp.state')
            ->pluck('total', 'mp.state')
            ->map(static fn ($value): int => (int) $value)
            ->all();
        $metrics['opted_out'] = (int) ($consent['opted_out'] ?? 0);
        $rates = [
            'delivery' => $metrics['sent'] > 0 ? round(($metrics['delivered'] / $metrics['sent']) * 100, 2) : 0.0,
            'read' => $metrics['delivered'] > 0 ? round(($metrics['read'] / $metrics['delivered']) * 100, 2) : 0.0,
            'reply' => $metrics['delivered'] > 0 ? round(($metrics['replied'] / $metrics['delivered']) * 100, 2) : 0.0,
            'failure' => $metrics['eligible'] > 0 ? round(($metrics['failed'] / $metrics['eligible']) * 100, 2) : 0.0,
        ];

        $inbox = DB::table('whats_app_conversations')
            ->whereIn('store_id', $storeIds)
            ->whereNotIn('status', ['RESOLVED', 'CLOSED'])
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(static fn ($value): int => (int) $value)
            ->all();

        $providerVisible = $actor->hasPermission('whatsapp.view');
        $providerHealth = $providerVisible
            ? DB::table('whats_app_connection_profiles')
                ->selectRaw('health_status, COUNT(*) as total')
                ->groupBy('health_status')
                ->pluck('total', 'health_status')
                ->map(static fn ($value): int => (int) $value)
                ->all()
            : [];

        return [
            'as_of' => now()->toIso8601String(),
            'metrics' => $metrics,
            'campaign_statuses' => $campaignStatuses,
            'rates' => $rates,
            'delivery_funnel' => [
                'selected' => (int) $metrics['recipients'],
                'eligible' => (int) $metrics['eligible'],
                'sent' => (int) $metrics['sent'],
                'delivered' => (int) $metrics['delivered'],
                'read' => (int) $metrics['read'],
                'replied' => (int) $metrics['replied'],
            ],
            'activity_trend' => $this->activityTrend($campaignIds),
            'suppression_reasons' => $suppressionReasons,
            'failure_reasons' => $failureReasons,
            'consent' => $consent,
            'inbox' => $inbox,
            'inbox_unresolved' => array_sum($inbox),
            'provider_visible' => $providerVisible,
            'provider_health' => $providerHealth,
            'notification_campaigns' => $this->notificationCampaigns($actor),
        ];
    }

    /** @return list<int> */
    private function visibleWhatsAppCampaignIds(User $actor): array
    {
        $ids = [];
        foreach (DB::table('whats_app_campaigns')->orderBy('id')->pluck('id') as $id) {
            try {
                $this->reports->assertVisible($actor, (int) $id);
                $ids[] = (int) $id;
            } catch (HttpException $exception) {
                if (! in_array($exception->getStatusCode(), [403, 404], true)) {
                    throw $exception;
                }
            }
        }

        return $ids;
    }

    /** @param list<int> $campaignIds
     * @return list<array{date:string,sent:int,delivered:int,read:int,replied:int,failed:int}>
     */
    private function activityTrend(array $campaignIds): array
    {
        $start = now()->subDays(13)->startOfDay();
        $rows = DB::table('whats_app_campaign_recipients')
            ->whereIn('campaign_id', $campaignIds)
            ->where(function ($query) use ($start): void {
                foreach (['sent_at', 'delivered_at', 'read_at', 'replied_at', 'failed_at'] as $column) {
                    $query->orWhere($column, '>=', $start);
                }
            })
            ->get(['sent_at', 'delivered_at', 'read_at', 'replied_at', 'failed_at']);

        $series = [];
        for ($offset = 0; $offset < 14; $offset++) {
            $date = $start->copy()->addDays($offset)->toDateString();
            $series[$date] = [
                'date' => $date,
                'sent' => 0,
                'delivered' => 0,
                'read' => 0,
                'replied' => 0,
                'failed' => 0,
            ];
        }

        foreach ($rows as $row) {
            foreach (['sent', 'delivered', 'read', 'replied', 'failed'] as $metric) {
                $value = $row->{$metric.'_at'} ?? null;
                if ($value === null) {
                    continue;
                }
                $date = Carbon::parse($value)->toDateString();
                if (isset($series[$date])) {
                    $series[$date][$metric]++;
                }
            }
        }

        return array_values($series);
    }

    /** @return array{visible:bool,total:int,statuses:array<string,int>,failed_runs:int} */
    private function notificationCampaigns(User $actor): array
    {
        $query = DB::table('notification_campaigns');

        if (! $actor->hasRole('SUPER_ADMIN')) {
            $b2cStoreIds = $this->scope->allowedStoreIds($actor, 'notifications.manage', 'b2c');
            $b2cStoreIds = DB::table('stores')
                ->whereIn('id', $b2cStoreIds)
                ->where('is_active', true)
                ->where('advertising_enabled', true)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
            $canB2b = $actor->hasRole('B2B_ADMIN') && $actor->hasPermission('notifications.manage');

            if (! $canB2b && $b2cStoreIds === []) {
                return ['visible' => false, 'total' => 0, 'statuses' => [], 'failed_runs' => 0];
            }

            $query->where(function ($scope) use ($canB2b, $b2cStoreIds): void {
                if ($canB2b) {
                    $scope->where('target_channel', 'b2b');
                } else {
                    $scope->whereRaw('1 = 0');
                }

                if ($b2cStoreIds !== []) {
                    $scope->orWhere(function ($b2c) use ($b2cStoreIds): void {
                        $b2c->where('target_channel', 'b2c')
                            ->whereIn('store_id', $b2cStoreIds);
                    });
                }
            });
        }

        $ids = (clone $query)->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $statuses = (clone $query)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(static fn ($value): int => (int) $value)
            ->all();
        $failedRuns = DB::table('notification_campaign_runs')
            ->whereIn('campaign_id', $ids)
            ->whereIn('status', ['failed', 'error'])
            ->count();

        return [
            'visible' => true,
            'total' => count($ids),
            'statuses' => $statuses,
            'failed_runs' => $failedRuns,
        ];
    }

    /** @param list<int> $ids
     * @return array<string,int>
     */
    private function countsByStatus(string $table, array $ids): array
    {
        return DB::table($table)
            ->whereIn('id', $ids)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(static fn ($value): int => (int) $value)
            ->all();
    }
}
