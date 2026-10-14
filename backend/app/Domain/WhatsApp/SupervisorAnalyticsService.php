<?php

namespace App\Domain\WhatsApp;

use App\Models\User;
use App\Services\OperationalTenantScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class SupervisorAnalyticsService
{
    public function __construct(private readonly OperationalTenantScope $scope) {}

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function dashboard(User $actor, array $filters): array
    {
        abort_unless(
            $actor->hasRole('SUPER_ADMIN')
                || $actor->hasPermission('whatsapp.customer_service.supervise'),
            403,
        );

        $allowedStoreIds = $this->scope->allowedStoreIds($actor, 'whatsapp.conversations.view');
        $storeIds = $allowedStoreIds;
        if (! empty($filters['store_id'])) {
            $storeId = (int) $filters['store_id'];
            abort_unless(in_array($storeId, $allowedStoreIds, true), 404);
            $storeIds = [$storeId];
        }

        $from = ! empty($filters['from'])
            ? Carbon::parse((string) $filters['from'])->startOfDay()
            : now()->subDays(29)->startOfDay();
        $to = ! empty($filters['to'])
            ? Carbon::parse((string) $filters['to'])->endOfDay()
            : now()->endOfDay();
        abort_unless($from->lte($to), 422);

        $agentId = ! empty($filters['agent_id']) ? (int) $filters['agent_id'] : null;

        $conversationQuery = DB::table('whats_app_conversations')
            ->whereIn('store_id', $storeIds);
        if ($agentId !== null) {
            $conversationQuery->where('primary_agent_id', $agentId);
        }

        $conversations = $conversationQuery->get([
            'id', 'platform_customer_id', 'store_id', 'status', 'primary_agent_id',
            'first_inbound_at', 'first_agent_response_at', 'resolved_at', 'created_at',
        ]);
        $conversationIds = $conversations->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        $firstResponseDurations = [];
        $resolutionDurations = [];
        foreach ($conversations as $conversation) {
            if ($conversation->first_inbound_at !== null
                && $conversation->first_agent_response_at !== null
                && Carbon::parse((string) $conversation->first_agent_response_at)->betweenIncluded($from, $to)) {
                $firstResponseDurations[] = (int) Carbon::parse((string) $conversation->first_inbound_at)
                    ->diffInSeconds(Carbon::parse((string) $conversation->first_agent_response_at));
            }
            if ($conversation->first_inbound_at !== null
                && $conversation->resolved_at !== null
                && Carbon::parse((string) $conversation->resolved_at)->betweenIncluded($from, $to)) {
                $resolutionDurations[] = (int) Carbon::parse((string) $conversation->first_inbound_at)
                    ->diffInSeconds(Carbon::parse((string) $conversation->resolved_at));
            }
        }

        $responseDurations = $this->responseDurations($conversationIds, $from, $to);
        $firstThreshold = max(1, (int) config('whatsapp.supervisor.sla_first_response_minutes', 5)) * 60;
        $responseThreshold = max(1, (int) config('whatsapp.supervisor.sla_response_minutes', 15)) * 60;
        $resolutionThreshold = max(1, (int) config('whatsapp.supervisor.sla_resolution_minutes', 240)) * 60;

        $outcomes = $conversationIds === []
            ? collect()
            : DB::table('whats_app_conversation_outcomes')
                ->whereIn('conversation_id', $conversationIds)
                ->whereBetween('recorded_at', [$from, $to])
                ->select('outcome_code', DB::raw('COUNT(*) as total'))
                ->groupBy('outcome_code')
                ->orderByDesc('total')
                ->get();

        $workload = $this->workload($actor, $storeIds, $conversations, $agentId);
        $campaigns = $this->campaignMetrics($storeIds, $from, $to);
        $leads = $this->leadMetrics($storeIds, $filters, $agentId);
        $followUps = $this->followUpMetrics($storeIds, $agentId);

        return [
            'period' => ['from' => $from, 'to' => $to],
            'store_ids' => $storeIds,
            'summary' => [
                'total' => $conversations->count(),
                'open' => $conversations->where('status', 'OPEN')->count(),
                'waiting' => $conversations->filter(static fn (object $row): bool => str_starts_with((string) $row->status, 'WAITING_'))->count(),
                'unassigned' => $conversations->whereNull('primary_agent_id')->count(),
                'resolved' => $conversations->filter(static fn (object $row): bool => in_array((string) $row->status, ['RESOLVED', 'CLOSED'], true))->count(),
            ],
            'sla' => [
                'first_response' => $this->sla($firstResponseDurations, $firstThreshold),
                'response' => $this->sla($responseDurations, $responseThreshold),
                'resolution' => $this->sla($resolutionDurations, $resolutionThreshold),
                'thresholds_minutes' => [
                    'first_response' => (int) ($firstThreshold / 60),
                    'response' => (int) ($responseThreshold / 60),
                    'resolution' => (int) ($resolutionThreshold / 60),
                ],
            ],
            'outcomes' => $outcomes,
            'workload' => $workload,
            'campaigns' => $campaigns,
            'leads' => $leads,
            'follow_ups' => $followUps,
        ];
    }

    /** @param list<int> $conversationIds
     * @return list<int>
     */
    private function responseDurations(array $conversationIds, Carbon $from, Carbon $to): array
    {
        if ($conversationIds === []) {
            return [];
        }

        $rows = DB::table('whats_app_messages')
            ->whereIn('conversation_id', $conversationIds)
            ->where('occurred_at', '<=', $to)
            ->whereIn('direction', ['inbound', 'outbound'])
            ->orderBy('conversation_id')
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get(['id', 'conversation_id', 'direction', 'actor_type', 'occurred_at']);

        $pending = [];
        $durations = [];

        foreach ($rows as $row) {
            $conversationId = (int) $row->conversation_id;
            $occurred = Carbon::parse((string) $row->occurred_at);

            if ((string) $row->direction === 'inbound') {
                if ($occurred->gte($from) && ! isset($pending[$conversationId])) {
                    $pending[$conversationId] = $occurred;
                }

                continue;
            }

            if ((string) $row->actor_type !== 'agent' || ! isset($pending[$conversationId])) {
                continue;
            }

            $durations[] = (int) $pending[$conversationId]->diffInSeconds($occurred);
            unset($pending[$conversationId]);
        }

        return $durations;
    }

    /** @param list<int> $durations */
    private function sla(array $durations, int $thresholdSeconds): array
    {
        sort($durations);
        $count = count($durations);
        $breaches = count(array_filter($durations, static fn (int $seconds): bool => $seconds > $thresholdSeconds));

        return [
            'samples' => $count,
            'average_minutes' => $count === 0 ? null : round((array_sum($durations) / $count) / 60, 2),
            'p90_minutes' => $count === 0 ? null : round($durations[(int) ceil($count * 0.9) - 1] / 60, 2),
            'breaches' => $breaches,
            'within_sla_percent' => $count === 0 ? null : round((($count - $breaches) / $count) * 100, 2),
        ];
    }

    /** @param list<int> $storeIds */
    private function workload(User $actor, array $storeIds, Collection $conversations, ?int $agentId): Collection
    {
        $loads = [];
        foreach ($conversations as $conversation) {
            if ($conversation->primary_agent_id === null
                || in_array((string) $conversation->status, ['RESOLVED', 'CLOSED'], true)) {
                continue;
            }
            $id = (int) $conversation->primary_agent_id;
            $loads[$id] = ($loads[$id] ?? 0) + 1;
        }

        return User::query()
            ->where('is_active', true)
            ->when($agentId !== null, fn ($query) => $query->whereKey($agentId))
            ->orderBy('name')
            ->get()
            ->filter(function (User $user) use ($actor, $storeIds): bool {
                if ($actor->hasRole('SUPER_ADMIN') && $user->hasRole('CUSTOMER_SERVICE_AGENT')) {
                    return true;
                }

                foreach ($storeIds as $storeId) {
                    if ($user->hasPermission('whatsapp.conversations.view', $storeId)) {
                        return true;
                    }
                }

                return false;
            })
            ->map(function (User $user) use ($loads): object {
                $profile = DB::table('whats_app_agent_profiles')->where('user_id', $user->getKey())->first();
                $capacity = max(1, (int) ($profile->capacity ?? 20));
                $load = (int) ($loads[(int) $user->getKey()] ?? 0);

                return (object) [
                    'id' => (int) $user->getKey(),
                    'name' => (string) $user->name,
                    'status' => (string) ($profile->status ?? 'OFFLINE'),
                    'accepts_new' => (bool) ($profile->accepts_new ?? false),
                    'capacity' => $capacity,
                    'load' => $load,
                    'utilization_percent' => round(($load / $capacity) * 100, 2),
                ];
            })
            ->values();
    }

    /** @param list<int> $storeIds */
    private function campaignMetrics(array $storeIds, Carbon $from, Carbon $to): array
    {
        $recipients = DB::table('whats_app_campaign_recipients as r')
            ->join('whats_app_campaigns as c', 'c.id', '=', 'r.campaign_id')
            ->join('platform_customers as pc', 'pc.id', '=', 'r.platform_customer_id')
            ->whereIn('pc.origin_store_id', $storeIds)
            ->whereNotNull('r.sent_at')
            ->whereBetween('r.sent_at', [$from, $to])
            ->orderBy('c.id')
            ->get([
                'r.id', 'r.platform_customer_id', 'r.sent_at', 'r.delivered_at', 'r.read_at', 'r.replied_at', 'r.failed_at',
                'c.id as campaign_id', 'c.name as campaign_name',
                'pc.legacy_customer_id',
            ]);

        $legacyIds = $recipients->pluck('legacy_customer_id')->filter()->map(static fn ($id): int => (int) $id)->unique()->values()->all();
        $windowDays = max(1, (int) config('whatsapp.supervisor.campaign_conversion_window_days', 7));
        $ordersByCustomer = collect();

        if ($legacyIds !== []) {
            $ordersByCustomer = DB::table('orders')
                ->whereIn('customer_id', $legacyIds)
                ->whereIn('store_id', $storeIds)
                ->whereBetween('created_at', [$from, $to->copy()->addDays($windowDays)])
                ->get(['id', 'customer_id', 'created_at'])
                ->groupBy(static fn (object $row): int => (int) $row->customer_id);
        }

        $grouped = [];
        foreach ($recipients as $row) {
            $campaignId = (int) $row->campaign_id;
            if (! isset($grouped[$campaignId])) {
                $grouped[$campaignId] = [
                    'id' => $campaignId,
                    'name' => (string) $row->campaign_name,
                    'sent' => 0,
                    'delivered' => 0,
                    'read' => 0,
                    'replied' => 0,
                    'failed' => 0,
                    'converted' => 0,
                ];
            }

            $grouped[$campaignId]['sent']++;
            $grouped[$campaignId]['delivered'] += $row->delivered_at === null ? 0 : 1;
            $grouped[$campaignId]['read'] += $row->read_at === null ? 0 : 1;
            $grouped[$campaignId]['replied'] += $row->replied_at === null ? 0 : 1;
            $grouped[$campaignId]['failed'] += $row->failed_at === null ? 0 : 1;

            $sentAt = Carbon::parse((string) $row->sent_at);
            $converted = collect($ordersByCustomer->get((int) $row->legacy_customer_id, []))
                ->contains(static function (object $order) use ($sentAt, $windowDays): bool {
                    $created = Carbon::parse((string) $order->created_at);

                    return $created->gte($sentAt) && $created->lte($sentAt->copy()->addDays($windowDays));
                });
            $grouped[$campaignId]['converted'] += $converted ? 1 : 0;
        }

        foreach ($grouped as &$campaign) {
            $sent = max(1, (int) $campaign['sent']);
            $campaign['delivery_rate'] = round(((int) $campaign['delivered'] / $sent) * 100, 2);
            $campaign['read_rate'] = round(((int) $campaign['read'] / $sent) * 100, 2);
            $campaign['reply_rate'] = round(((int) $campaign['replied'] / $sent) * 100, 2);
            $campaign['conversion_rate'] = round(((int) $campaign['converted'] / $sent) * 100, 2);
        }
        unset($campaign);

        return [
            'conversion_window_days' => $windowDays,
            'rows' => array_values($grouped),
            'totals' => [
                'sent' => $recipients->count(),
                'delivered' => $recipients->whereNotNull('delivered_at')->count(),
                'read' => $recipients->whereNotNull('read_at')->count(),
                'replied' => $recipients->whereNotNull('replied_at')->count(),
                'failed' => $recipients->whereNotNull('failed_at')->count(),
                'converted' => array_sum(array_column($grouped, 'converted')),
            ],
        ];
    }

    /** @param list<int> $storeIds
     * @param array<string, mixed> $filters
     */
    private function leadMetrics(array $storeIds, array $filters, ?int $agentId): array
    {
        $query = DB::table('whats_app_leads as l')
            ->leftJoin('platform_customers as pc', 'pc.id', '=', 'l.platform_customer_id')
            ->leftJoin('users as u', 'u.id', '=', 'l.assigned_agent_id')
            ->whereIn('l.store_id', $storeIds);

        if (! empty($filters['lead_temperature'])) {
            $query->where('l.temperature', (string) $filters['lead_temperature']);
        }
        if ($agentId !== null) {
            $query->where('l.assigned_agent_id', $agentId);
        }

        $rows = $query
            ->orderByRaw("CASE l.temperature WHEN 'hot' THEN 0 WHEN 'warm' THEN 1 ELSE 2 END")
            ->orderByDesc('l.last_activity_at')
            ->limit(100)
            ->get([
                'l.*', 'pc.name as customer_name', 'pc.phone as customer_phone', 'u.name as agent_name',
            ]);

        return [
            'total' => $rows->count(),
            'cold' => $rows->where('temperature', 'cold')->count(),
            'warm' => $rows->where('temperature', 'warm')->count(),
            'hot' => $rows->where('temperature', 'hot')->count(),
            'converted' => $rows->where('status', 'converted')->count(),
            'rows' => $rows,
        ];
    }

    /** @param list<int> $storeIds */
    private function followUpMetrics(array $storeIds, ?int $agentId): array
    {
        $query = DB::table('whats_app_follow_ups as f')
            ->leftJoin('platform_customers as pc', 'pc.id', '=', 'f.platform_customer_id')
            ->leftJoin('users as u', 'u.id', '=', 'f.assigned_agent_id')
            ->whereIn('f.store_id', $storeIds);
        if ($agentId !== null) {
            $query->where('f.assigned_agent_id', $agentId);
        }

        $rows = $query
            ->orderByRaw("CASE f.status WHEN 'open' THEN 0 ELSE 1 END")
            ->orderByRaw('CASE WHEN f.due_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('f.due_at')
            ->limit(100)
            ->get(['f.*', 'pc.name as customer_name', 'u.name as agent_name']);

        $open = $rows->where('status', 'open');

        return [
            'open' => $open->count(),
            'overdue' => $open->filter(static fn (object $row): bool => $row->due_at !== null && Carbon::parse((string) $row->due_at)->lt(now()))->count(),
            'completed' => $rows->where('status', 'completed')->count(),
            'rows' => $rows,
        ];
    }
}
