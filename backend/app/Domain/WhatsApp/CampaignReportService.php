<?php

namespace App\Domain\WhatsApp;

use App\Models\User;
use App\Services\OperationalTenantScope;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class CampaignReportService
{
    public function __construct(
        private readonly CampaignService $campaigns,
        private readonly OperationalTenantScope $scope,
    ) {}

    public function assertVisible(User $actor, int $campaignId, string $permission = 'whatsapp.campaigns.view'): object
    {
        $campaign = DB::table('whats_app_campaigns as c')
            ->leftJoin('whats_app_templates as t', 't.id', '=', 'c.template_id')
            ->where('c.id', $campaignId)
            ->select('c.*', 't.name as template_name', 't.provider_name', 't.locale as template_locale')
            ->first();
        abort_unless($campaign !== null, 404);

        if ($actor->hasRole('SUPER_ADMIN')) {
            return $campaign;
        }

        $allowed = $this->scope->allowedStoreIds($actor, $permission);
        abort_unless($allowed !== [], 403);

        $campaignStores = $this->campaignStoreIds($campaign);
        abort_unless($campaignStores !== [] && array_diff($campaignStores, $allowed) === [], 403);

        return $campaign;
    }

    /** @return array<string,mixed> */
    public function overview(User $actor, int $campaignId): array
    {
        $campaign = $this->assertVisible($actor, $campaignId);
        $metrics = $this->campaigns->metrics($campaignId);
        $eligible = max(0, (int) $metrics['eligible']);
        $sent = max(0, (int) $metrics['sent']);
        $delivered = max(0, (int) $metrics['delivered']);
        $read = max(0, (int) $metrics['read']);
        $replied = max(0, (int) $metrics['replied']);
        $failed = max(0, (int) $metrics['failed']);

        $suppression = DB::table('whats_app_campaign_recipients')
            ->where('campaign_id', $campaignId)
            ->where('eligibility_status', 'suppressed')
            ->selectRaw("COALESCE(suppression_reason, 'unknown') as reason, COUNT(*) as total")
            ->groupBy('suppression_reason')
            ->orderByDesc('total')
            ->pluck('total', 'reason')
            ->map(static fn ($value): int => (int) $value)
            ->all();

        $failures = DB::table('whats_app_campaign_recipients as r')
            ->leftJoin('whats_app_outbound_dispatches as d', 'd.message_id', '=', 'r.message_id')
            ->where('r.campaign_id', $campaignId)
            ->where(function ($query): void {
                $query->whereNotNull('r.failed_at')
                    ->orWhere('r.send_status', 'failed')
                    ->orWhereNotNull('d.last_error');
            })
            ->selectRaw("COALESCE(d.last_error, r.suppression_reason, r.send_status, 'unknown') as reason, COUNT(DISTINCT r.id) as total")
            ->groupBy('reason')
            ->orderByDesc('total')
            ->pluck('total', 'reason')
            ->map(static fn ($value): int => (int) $value)
            ->all();

        $optOutQuery = $this->optOutQuery($campaignId, $campaign);
        $optOuts = (clone $optOutQuery)
            ->distinct()
            ->count('mc.platform_customer_id');
        $optOutDetails = (clone $optOutQuery)
            ->leftJoin('platform_customers as pc', 'pc.id', '=', 'mc.platform_customer_id')
            ->orderByDesc('mc.occurred_at')
            ->limit(100)
            ->get([
                'mc.platform_customer_id',
                'pc.name as customer_name',
                'mc.phone',
                'mc.source',
                'mc.occurred_at',
            ]);

        $followups = DB::table('whats_app_campaign_followups as f')
            ->join('whats_app_campaign_recipients as r', 'r.id', '=', 'f.campaign_recipient_id')
            ->where('r.campaign_id', $campaignId)
            ->selectRaw('f.status, COUNT(*) as total')
            ->groupBy('f.status')
            ->pluck('total', 'f.status')
            ->map(static fn ($value): int => (int) $value)
            ->all();
        $followups['overdue'] = DB::table('whats_app_campaign_followups as f')
            ->join('whats_app_campaign_recipients as r', 'r.id', '=', 'f.campaign_recipient_id')
            ->where('r.campaign_id', $campaignId)
            ->whereNotNull('f.due_at')
            ->where('f.due_at', '<', now())
            ->whereNotIn('f.status', ['completed', 'closed', 'cancelled'])
            ->count();

        return [
            'campaign' => $campaign,
            'metrics' => $metrics,
            'rates' => [
                'delivery' => $sent > 0 ? round(($delivered / $sent) * 100, 2) : 0.0,
                'read' => $delivered > 0 ? round(($read / $delivered) * 100, 2) : 0.0,
                'reply' => $delivered > 0 ? round(($replied / $delivered) * 100, 2) : 0.0,
                'failure' => $eligible > 0 ? round(($failed / $eligible) * 100, 2) : 0.0,
                'opt_out' => $sent > 0 ? round(((int) $optOuts / $sent) * 100, 2) : 0.0,
            ],
            'suppression' => $suppression,
            'failures' => $failures,
            'opt_outs' => (int) $optOuts,
            'opt_out_details' => $optOutDetails,
            'followups' => $followups,
            'funnel' => [
                'selected' => (int) $metrics['total'],
                'eligible' => $eligible,
                'sent' => $sent,
                'delivered' => $delivered,
                'read' => $read,
                'replied' => $replied,
            ],
        ];
    }

    /** @param array<string,mixed> $filters */
    public function recipients(User $actor, int $campaignId, array $filters = []): LengthAwarePaginator
    {
        $this->assertVisible($actor, $campaignId);

        $query = DB::table('whats_app_campaign_recipients as r')
            ->leftJoin('platform_customers as pc', 'pc.id', '=', 'r.platform_customer_id')
            ->leftJoin('stores as s', 's.id', '=', 'pc.origin_store_id')
            ->leftJoin('whats_app_marketing_preferences as mp', 'mp.platform_customer_id', '=', 'pc.id')
            ->leftJoin('whats_app_outbound_dispatches as d', 'd.message_id', '=', 'r.message_id')
            ->leftJoin('whats_app_campaign_followups as f', 'f.campaign_recipient_id', '=', 'r.id')
            ->leftJoin('users as agent', 'agent.id', '=', 'f.assigned_agent_id')
            ->where('r.campaign_id', $campaignId)
            ->select([
                'r.*',
                'pc.name as customer_name',
                'pc.email as customer_email',
                'pc.origin_channel',
                'pc.origin_store_id',
                's.name as store_name',
                'mp.state as consent_state',
                'd.provider_message_ref',
                'd.last_error',
                'f.status as followup_status',
                'f.due_at as followup_due_at',
                'f.conversation_id',
                'agent.name as agent_name',
            ]);

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';
            $query->where(function ($builder) use ($like): void {
                $builder->where('pc.name', 'like', $like)
                    ->orWhere('r.phone', 'like', $like)
                    ->orWhere('pc.email', 'like', $like);
            });
        }
        $sendStatus = trim((string) ($filters['send_status'] ?? ''));
        if ($sendStatus !== '') {
            $query->where('r.send_status', $sendStatus);
        }
        $eligibility = trim((string) ($filters['eligibility_status'] ?? ''));
        if ($eligibility !== '') {
            $query->where('r.eligibility_status', $eligibility);
        }
        $suppression = trim((string) ($filters['suppression_reason'] ?? ''));
        if ($suppression !== '') {
            $query->where('r.suppression_reason', $suppression);
        }
        $consent = trim((string) ($filters['consent_state'] ?? ''));
        if ($consent !== '') {
            $query->where('mp.state', $consent);
        }

        $storeId = (int) ($filters['store_id'] ?? 0);
        if ($storeId > 0) {
            $this->scope->assertStore($actor, $storeId, 'whatsapp.campaigns.view');
            $query->where('pc.origin_store_id', $storeId);
        }

        $channel = strtolower(trim((string) ($filters['channel'] ?? '')));
        if (in_array($channel, ['b2b', 'b2c'], true)) {
            $query->where('pc.origin_channel', $channel);
        }

        $agentId = (int) ($filters['agent_id'] ?? 0);
        if ($agentId > 0) {
            $query->where('f.assigned_agent_id', $agentId);
        }

        if (! empty($filters['from'])) {
            $query->where('r.created_at', '>=', Carbon::parse((string) $filters['from'])->startOfDay());
        }
        if (! empty($filters['to'])) {
            $query->where('r.created_at', '<=', Carbon::parse((string) $filters['to'])->endOfDay());
        }

        $paginator = $query->orderBy('r.id')->paginate(50)->withQueryString();
        $paginator->setCollection(
            $paginator->getCollection()->map(fn (object $row): object => $this->decorateRecipient($row)),
        );

        return $paginator;
    }

    /** @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public function comparison(User $actor, array $filters = []): array
    {
        $query = DB::table('whats_app_campaigns as c')
            ->leftJoin('whats_app_templates as t', 't.id', '=', 'c.template_id')
            ->orderByDesc('c.id')
            ->limit(100)
            ->select('c.*', 't.name as template_name');

        if (! empty($filters['from'])) {
            $query->where('c.created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('c.created_at', '<=', $filters['to']);
        }
        if (! empty($filters['template_id'])) {
            $query->where('c.template_id', (int) $filters['template_id']);
        }
        if (! empty($filters['status'])) {
            $query->where('c.status', (string) $filters['status']);
        }

        $rows = [];
        foreach ($query->get() as $campaign) {
            try {
                $this->assertVisible($actor, (int) $campaign->id);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
                continue;
            }
            $metrics = $this->campaigns->metrics((int) $campaign->id);
            $sent = (int) $metrics['sent'];
            $delivered = (int) $metrics['delivered'];
            $read = (int) $metrics['read'];
            $replied = (int) $metrics['replied'];

            $optOuts = $this->optOutCount((int) $campaign->id, $campaign);

            $rows[] = [
                'id' => (int) $campaign->id,
                'name' => (string) $campaign->name,
                'template' => $campaign->template_name,
                'status' => (string) $campaign->status,
                'created_at' => $campaign->created_at,
                'sent' => $sent,
                'delivered' => $delivered,
                'read' => $read,
                'replied' => $replied,
                'failed' => (int) $metrics['failed'],
                'opt_outs' => $optOuts,
                'delivery_rate' => $sent > 0 ? round(($delivered / $sent) * 100, 2) : 0.0,
                'read_rate' => $delivered > 0 ? round(($read / $delivered) * 100, 2) : 0.0,
                'reply_rate' => $delivered > 0 ? round(($replied / $delivered) * 100, 2) : 0.0,
                'opt_out_rate' => $sent > 0 ? round(($optOuts / $sent) * 100, 2) : 0.0,
            ];
        }

        return $rows;
    }

    /** @return Collection<int,object> */
    /** @param array<string,mixed> $filters */
    public function exportRows(User $actor, int $campaignId, array $filters = []): Collection
    {
        $this->assertVisible($actor, $campaignId);

        $query = DB::table('whats_app_campaign_recipients as r')
            ->leftJoin('platform_customers as pc', 'pc.id', '=', 'r.platform_customer_id')
            ->leftJoin('stores as s', 's.id', '=', 'pc.origin_store_id')
            ->leftJoin('whats_app_marketing_preferences as mp', 'mp.platform_customer_id', '=', 'pc.id')
            ->leftJoin('whats_app_outbound_dispatches as d', 'd.message_id', '=', 'r.message_id')
            ->leftJoin('whats_app_campaign_followups as f', 'f.campaign_recipient_id', '=', 'r.id')
            ->leftJoin('users as agent', 'agent.id', '=', 'f.assigned_agent_id')
            ->where('r.campaign_id', $campaignId);

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';
            $query->where(function ($builder) use ($like): void {
                $builder->where('pc.name', 'like', $like)
                    ->orWhere('r.phone', 'like', $like)
                    ->orWhere('pc.email', 'like', $like);
            });
        }
        foreach ([
            'send_status' => 'r.send_status',
            'eligibility_status' => 'r.eligibility_status',
            'suppression_reason' => 'r.suppression_reason',
            'consent_state' => 'mp.state',
        ] as $filter => $column) {
            $value = trim((string) ($filters[$filter] ?? ''));
            if ($value !== '') {
                $query->where($column, $value);
            }
        }

        $storeId = (int) ($filters['store_id'] ?? 0);
        if ($storeId > 0) {
            $this->scope->assertStore($actor, $storeId, 'whatsapp.campaigns.view');
            $query->where('pc.origin_store_id', $storeId);
        }
        $channel = strtolower(trim((string) ($filters['channel'] ?? '')));
        if (in_array($channel, ['b2b', 'b2c'], true)) {
            $query->where('pc.origin_channel', $channel);
        }
        $agentId = (int) ($filters['agent_id'] ?? 0);
        if ($agentId > 0) {
            $query->where('f.assigned_agent_id', $agentId);
        }
        if (! empty($filters['from'])) {
            $query->where('r.created_at', '>=', Carbon::parse((string) $filters['from'])->startOfDay());
        }
        if (! empty($filters['to'])) {
            $query->where('r.created_at', '<=', Carbon::parse((string) $filters['to'])->endOfDay());
        }

        return $query->orderBy('r.id')->get([
            'pc.name as customer_name',
            'r.phone',
            'pc.email',
            'pc.origin_channel',
            's.name as store_name',
            'mp.state as consent_state',
            'r.eligibility_status',
            'r.suppression_reason',
            'r.send_status',
            'd.provider_message_ref',
            'r.queued_at',
            'r.sent_at',
            'r.delivered_at',
            'r.read_at',
            'r.failed_at',
            'r.replied_at',
            'd.last_error',
            'f.status as followup_status',
            'f.due_at as followup_due_at',
            'agent.name as agent_name',
            'f.conversation_id',
        ])->map(fn (object $row): object => $this->decorateRecipient($row));
    }

    private function decorateRecipient(object $row): object
    {
        $row->first_reply_seconds = null;
        if ($row->sent_at !== null && $row->replied_at !== null) {
            $row->first_reply_seconds = Carbon::parse((string) $row->sent_at)
                ->diffInSeconds(Carbon::parse((string) $row->replied_at));
        }

        $terminalFollowup = in_array(
            strtolower((string) ($row->followup_status ?? '')),
            ['completed', 'closed', 'cancelled'],
            true,
        );
        $row->followup_overdue = $row->followup_due_at !== null
            && ! $terminalFollowup
            && Carbon::parse((string) $row->followup_due_at)->isPast();

        return $row;
    }

    private function optOutCount(int $campaignId, object $campaign): int
    {
        return (int) $this->optOutQuery($campaignId, $campaign)
            ->distinct()
            ->count('mc.platform_customer_id');
    }

    private function optOutQuery(int $campaignId, object $campaign): \Illuminate\Database\Query\Builder
    {
        $windowStart = $campaign->started_at ?? $campaign->scheduled_at ?? $campaign->created_at;
        $windowEnd = $campaign->completed_at === null
            ? now()
            : Carbon::parse((string) $campaign->completed_at)->addDays(7);

        return DB::table('whats_app_marketing_consents as mc')
            ->join('whats_app_campaign_recipients as r', 'r.platform_customer_id', '=', 'mc.platform_customer_id')
            ->where('r.campaign_id', $campaignId)
            ->where('mc.state', MarketingConsentService::OPTED_OUT)
            ->where('mc.occurred_at', '>=', $windowStart)
            ->where('mc.occurred_at', '<=', $windowEnd);
    }

    /** @return list<int> */
    private function campaignStoreIds(object $campaign): array
    {
        $segment = json_decode((string) ($campaign->segment ?? '{}'), true);
        $segment = is_array($segment) ? $segment : [];
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, (array) ($segment['store_ids'] ?? [])),
            static fn (int $id): bool => $id > 0,
        )));
        if ($ids !== []) {
            return $ids;
        }

        $customerIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, (array) ($segment['customer_ids'] ?? [])),
            static fn (int $id): bool => $id > 0,
        )));
        if ($customerIds !== []) {
            return DB::table('platform_customers')
                ->whereIn('id', $customerIds)
                ->whereNotNull('origin_store_id')
                ->pluck('origin_store_id')
                ->map(static fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();
        }

        return DB::table('whats_app_campaign_recipients as r')
            ->join('platform_customers as pc', 'pc.id', '=', 'r.platform_customer_id')
            ->where('r.campaign_id', (int) $campaign->id)
            ->whereNotNull('pc.origin_store_id')
            ->pluck('pc.origin_store_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
