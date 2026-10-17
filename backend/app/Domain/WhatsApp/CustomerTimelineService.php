<?php

namespace App\Domain\WhatsApp;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class CustomerTimelineService
{
    /** @param list<int> $storeIds
     * @return Collection<int, array<string, mixed>>
     */
    public function forCustomer(User $actor, int $platformCustomerId, array $storeIds, int $limit = 150): Collection
    {
        $customer = DB::table('platform_customers')->where('id', $platformCustomerId)->first();
        abort_unless($customer !== null, 404);

        $legacyId = (int) $customer->legacy_customer_id;
        $visible = ($customer->origin_store_id !== null && in_array((int) $customer->origin_store_id, $storeIds, true))
            || DB::table('whats_app_conversations')->where('platform_customer_id', $platformCustomerId)->whereIn('store_id', $storeIds)->exists()
            || DB::table('orders')->where('customer_id', $legacyId)->whereIn('store_id', $storeIds)->exists()
            || DB::table('b2b_customers')->where('user_id', $customer->user_id)->exists()
            || DB::table('retail_wholesale_accounts')
                ->join('b2b_accounts', 'b2b_accounts.b2b_customer_id', '=', 'retail_wholesale_accounts.b2b_customer_id')
                ->where('retail_wholesale_accounts.owner_user_id', $customer->user_id)
                ->where('b2b_accounts.status', 'active')
                ->exists();
        abort_unless($actor->hasRole('SUPER_ADMIN') || $visible, 404);

        $whatsAppStoreIds = array_values(array_filter(
            $storeIds,
            static fn (int $storeId): bool => $actor->hasRole('SUPER_ADMIN')
                || $actor->hasPermission('whatsapp.inbox.view', $storeId),
        ));
        $caseStoreIds = array_values(array_filter(
            $storeIds,
            static fn (int $storeId): bool => $actor->hasRole('SUPER_ADMIN')
                || $actor->hasPermission('customers.view', $storeId)
                || $actor->hasPermission('b2b.accounts.view', $storeId)
                || $actor->hasPermission('whatsapp.inbox.view', $storeId),
        ));

        $events = collect();

        $messages = DB::table('whats_app_messages as m')
            ->join('whats_app_conversations as c', 'c.id', '=', 'm.conversation_id')
            ->leftJoin('users as u', 'u.id', '=', 'm.actor_user_id')
            ->where('c.platform_customer_id', $platformCustomerId)
            ->whereIn('c.store_id', $whatsAppStoreIds)
            ->get([
                'm.id', 'm.direction', 'm.actor_type', 'm.body', 'm.delivery_status',
                'm.occurred_at', 'c.id as conversation_id', 'c.store_id', 'u.name as actor_name',
            ])
            ->map(static fn (object $row): array => [
                'kind' => 'message',
                'source_id' => (int) $row->id,
                'store_id' => (int) $row->store_id,
                'title' => __('whatsapp_supervisor.directions.'.strtolower((string) $row->direction))
                    .' · '.($row->actor_name ?? __('whatsapp_supervisor.actors.'.strtolower((string) $row->actor_type))),
                'detail' => $row->body,
                'status' => $row->delivery_status,
                'occurred_at' => $row->occurred_at,
                'conversation_id' => (int) $row->conversation_id,
            ]);
        $events = $events->concat($messages);

        $notes = DB::table('whats_app_internal_notes as n')
            ->join('whats_app_conversations as c', 'c.id', '=', 'n.conversation_id')
            ->leftJoin('users as u', 'u.id', '=', 'n.author_user_id')
            ->where('c.platform_customer_id', $platformCustomerId)
            ->whereIn('c.store_id', $whatsAppStoreIds)
            ->get(['n.id', 'n.body', 'n.created_at', 'c.id as conversation_id', 'c.store_id', 'u.name as actor_name'])
            ->map(static fn (object $row): array => [
                'kind' => 'internal_note',
                'source_id' => (int) $row->id,
                'store_id' => (int) $row->store_id,
                'title' => __('whatsapp_supervisor.timeline_titles.internal_note')
                    .' · '.($row->actor_name ?? __('whatsapp_supervisor.actors.system')),
                'detail' => $row->body,
                'status' => null,
                'occurred_at' => $row->created_at,
                'conversation_id' => (int) $row->conversation_id,
            ]);
        $events = $events->concat($notes);

        $assignments = DB::table('whats_app_assignment_history as h')
            ->leftJoin('users as a', 'a.id', '=', 'h.new_primary_agent_id')
            ->where('h.platform_customer_id', $platformCustomerId)
            ->whereIn('h.store_id', $whatsAppStoreIds)
            ->get(['h.id', 'h.source', 'h.reason', 'h.occurred_at', 'h.conversation_id', 'h.store_id', 'a.name as agent_name'])
            ->map(static fn (object $row): array => [
                'kind' => 'assignment',
                'source_id' => (int) $row->id,
                'store_id' => (int) $row->store_id,
                'title' => __('whatsapp_supervisor.timeline_titles.assignment')
                    .' · '.($row->agent_name ?? __('whatsapp.inbox.unassigned')),
                'detail' => $row->reason,
                'status' => $row->source,
                'occurred_at' => $row->occurred_at,
                'conversation_id' => $row->conversation_id === null ? null : (int) $row->conversation_id,
            ]);
        $events = $events->concat($assignments);

        $crm = DB::table('whats_app_crm_events')
            ->where('platform_customer_id', $platformCustomerId)
            ->whereIn('store_id', $whatsAppStoreIds)
            ->get(['id', 'event_type', 'payload', 'occurred_at', 'conversation_id', 'store_id'])
            ->map(static function (object $row): array {
                $payload = json_decode((string) ($row->payload ?? '{}'), true);

                return [
                    'kind' => 'crm',
                    'source_id' => (int) $row->id,
                    'store_id' => $row->store_id === null ? null : (int) $row->store_id,
                    'title' => __('whatsapp_supervisor.crm_event_types.'.strtolower((string) $row->event_type)),
                    'detail' => is_array($payload) ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                    'status' => null,
                    'occurred_at' => $row->occurred_at,
                    'conversation_id' => $row->conversation_id === null ? null : (int) $row->conversation_id,
                ];
            });
        $events = $events->concat($crm);

        $campaigns = $customer->origin_store_id !== null
            && in_array((int) $customer->origin_store_id, $whatsAppStoreIds, true)
            ? DB::table('whats_app_campaign_recipients as r')
                ->join('whats_app_campaigns as c', 'c.id', '=', 'r.campaign_id')
                ->where('r.platform_customer_id', $platformCustomerId)
                ->whereNotNull('r.queued_at')
                ->get([
                    'r.id', 'r.send_status', 'r.queued_at', 'r.sent_at', 'r.delivered_at', 'r.read_at', 'r.replied_at',
                    'c.id as campaign_id', 'c.name as campaign_name',
                ])
            : collect();
        foreach ($campaigns as $row) {
            foreach ([
                'queued_at' => __('whatsapp_supervisor.timeline_titles.campaign_queued'),
                'sent_at' => __('whatsapp_supervisor.timeline_titles.campaign_sent'),
                'delivered_at' => __('whatsapp_supervisor.timeline_titles.campaign_delivered'),
                'read_at' => __('whatsapp_supervisor.timeline_titles.campaign_read'),
                'replied_at' => __('whatsapp_supervisor.timeline_titles.campaign_replied'),
            ] as $column => $title) {
                if ($row->{$column} === null) {
                    continue;
                }
                $events->push([
                    'kind' => 'campaign',
                    'source_id' => (int) $row->id,
                    'store_id' => $customer->origin_store_id,
                    'title' => $title.' · '.$row->campaign_name,
                    'detail' => 'Campaign #'.(int) $row->campaign_id,
                    'status' => $row->send_status,
                    'occurred_at' => $row->{$column},
                    'conversation_id' => null,
                ]);
            }
        }

        $transactional = DB::table('whats_app_transactional_events')
            ->where('platform_customer_id', $platformCustomerId)
            ->whereIn('store_id', $whatsAppStoreIds)
            ->select(['id', 'event_name', 'event_key', 'status', 'store_id'])
            ->addSelect(DB::raw('COALESCE(processed_at, queued_at, created_at) as occurred_at'))
            ->get();
        foreach ($transactional as $row) {
            $events->push([
                'kind' => 'transactional',
                'source_id' => (int) $row->id,
                'store_id' => $row->store_id === null ? null : (int) $row->store_id,
                'title' => __('whatsapp_supervisor.timeline_titles.transactional').' · '.(string) $row->event_name,
                'detail' => (string) $row->event_key,
                'status' => (string) $row->status,
                'occurred_at' => $row->occurred_at,
                'conversation_id' => null,
            ]);
        }

        $serviceCases = DB::table('customer_service_cases')
            ->where('platform_customer_id', $platformCustomerId)
            ->whereIn('store_id', $caseStoreIds)
            ->get([
                'id', 'subject', 'status', 'priority', 'queue', 'store_id',
                'sla_target_at', 'resolved_at', 'updated_at',
            ]);
        foreach ($serviceCases as $row) {
            $events->push([
                'kind' => 'service_case',
                'source_id' => (int) $row->id,
                'store_id' => (int) $row->store_id,
                'title' => __('customer_service_workspace.cases.case').' #'.(int) $row->id
                    .' · '.((string) ($row->subject ?: __('customer_service_workspace.values.untitled'))),
                'detail' => __('customer_service_workspace.values.'.(string) $row->priority)
                    .' · '.__('customer_service_workspace.values.'.(string) ($row->queue ?: 'general')),
                'status' => (string) $row->status,
                'occurred_at' => $row->resolved_at ?? $row->updated_at,
                'conversation_id' => null,
                'service_case_id' => (int) $row->id,
                'sla_target_at' => $row->sla_target_at,
            ]);
        }

        $orders = DB::table('orders')
            ->where('customer_id', $legacyId)
            ->whereIn('store_id', $storeIds)
            ->get(['id', 'order_number', 'status', 'grand_total', 'currency', 'store_id', 'created_at']);
        foreach ($orders as $row) {
            $events->push([
                'kind' => 'order',
                'source_id' => (int) $row->id,
                'store_id' => (int) $row->store_id,
                'title' => __('whatsapp_supervisor.timeline_titles.order').' '.$row->order_number,
                'detail' => trim((string) $row->currency.' '.number_format((float) $row->grand_total, 3)),
                'status' => (string) $row->status,
                'occurred_at' => $row->created_at,
                'conversation_id' => null,
            ]);
        }

        $financeStoreIds = array_values(array_filter(
            $storeIds,
            static fn (int $storeId): bool => $actor->hasPermission('finance.view', $storeId),
        ));

        if ($financeStoreIds !== []) {
            $invoices = DB::table('invoices')
                ->where('customer_id', $legacyId)
                ->whereIn('store_id', $financeStoreIds)
                ->get(['id', 'invoice_number', 'status', 'total', 'currency', 'store_id', 'issued_at']);
            foreach ($invoices as $row) {
                $events->push([
                    'kind' => 'invoice',
                    'source_id' => (int) $row->id,
                    'store_id' => (int) $row->store_id,
                    'title' => __('whatsapp_supervisor.timeline_titles.invoice').' '.$row->invoice_number,
                    'detail' => trim((string) $row->currency.' '.number_format((float) $row->total, 3)),
                    'status' => (string) $row->status,
                    'occurred_at' => $row->issued_at,
                    'conversation_id' => null,
                ]);
            }

            $payments = DB::table('payments as p')
                ->join('invoices as i', 'i.id', '=', 'p.invoice_id')
                ->where('i.customer_id', $legacyId)
                ->whereIn('i.store_id', $financeStoreIds)
                ->get(['p.id', 'p.provider', 'p.status', 'p.amount', 'p.currency', 'p.created_at', 'i.store_id']);
            foreach ($payments as $row) {
                $events->push([
                    'kind' => 'payment',
                    'source_id' => (int) $row->id,
                    'store_id' => (int) $row->store_id,
                    'title' => __('whatsapp_supervisor.timeline_titles.payment').' · '.(string) $row->provider,
                    'detail' => trim((string) $row->currency.' '.number_format((float) $row->amount, 3)),
                    'status' => (string) $row->status,
                    'occurred_at' => $row->created_at,
                    'conversation_id' => null,
                ]);
            }
        }

        return $events
            ->filter(static fn (array $row): bool => $row['occurred_at'] !== null)
            ->sortByDesc(static fn (array $row): int => Carbon::parse((string) $row['occurred_at'])->getTimestamp())
            ->take(max(1, min(500, $limit)))
            ->values();
    }
}
