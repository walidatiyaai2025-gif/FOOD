<?php

namespace App\Services;

use App\Models\CustomerServiceCase;
use App\Models\PlatformCustomer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CustomerServiceCaseManager
{
    private const VIEW_PERMISSIONS = [
        'customers.view',
        'b2b.accounts.view',
        'whatsapp.inbox.view',
    ];

    private const MANAGE_PERMISSIONS = [
        'customers.manage',
        'b2b.accounts.manage',
        'whatsapp.customer_service.supervise',
    ];

    public function __construct(
        private readonly OperationalTenantScope $tenantScope,
        private readonly AuditLogger $audit,
    ) {}

    /** @return list<int> */
    public function viewStoreIds(User $actor): array
    {
        return $this->storeIdsForPermissions($actor, self::VIEW_PERMISSIONS);
    }

    /** @return list<int> */
    public function manageStoreIds(User $actor): array
    {
        return $this->storeIdsForPermissions($actor, self::MANAGE_PERMISSIONS);
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    public function dashboard(User $actor, array $filters = []): array
    {
        $storeIds = $this->viewStoreIds($actor);
        abort_if($storeIds === [], 403);

        [$from, $to] = $this->dateWindow($filters['from'] ?? null, $filters['to'] ?? null);
        $customerQuery = $this->visibleCustomersQuery($storeIds);

        $activeCustomers = (clone $customerQuery)->where('pc.is_active', true)->distinct()->count('pc.id');
        $newCustomers = (clone $customerQuery)
            ->where('pc.is_active', true)
            ->whereBetween('pc.registered_at', [$from->startOfDay(), $to->endOfDay()])
            ->distinct()
            ->count('pc.id');

        $repeatBase = DB::table('platform_customers as pc')
            ->join('orders as o', 'o.customer_id', '=', 'pc.legacy_customer_id')
            ->where('pc.is_active', true)
            ->whereIn('o.store_id', $storeIds)
            ->whereBetween('o.created_at', [$from->startOfDay(), $to->endOfDay()])
            ->groupBy('pc.id')
            ->havingRaw('COUNT(o.id) >= 2')
            ->select('pc.id');
        $repeatCustomers = DB::query()->fromSub($repeatBase, 'repeat_customers')->count();

        $caseBase = DB::table('customer_service_cases')->whereIn('store_id', $storeIds);
        $openCases = (clone $caseBase)->whereIn('status', ['open', 'pending'])->count();
        $overdueCases = (clone $caseBase)
            ->whereIn('status', ['open', 'pending'])
            ->whereNotNull('sla_target_at')
            ->where('sla_target_at', '<', now())
            ->count();

        $serviceRiskCustomerIds = (clone $caseBase)
            ->whereNotNull('platform_customer_id')
            ->where(function ($query): void {
                $query->whereIn('priority', ['high', 'urgent'])
                    ->orWhereNotNull('service_recovery_code')
                    ->orWhere(function ($overdue): void {
                        $overdue->whereIn('status', ['open', 'pending'])
                            ->whereNotNull('sla_target_at')
                            ->where('sla_target_at', '<', now());
                    });
            })
            ->pluck('platform_customer_id')
            ->map(static fn ($id): int => (int) $id);

        $financeRiskCustomerIds = DB::table('invoices')
            ->whereIn('store_id', $storeIds)
            ->whereNotNull('platform_customer_id')
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->whereNotIn('status', ['paid', 'void', 'cancelled'])
            ->pluck('platform_customer_id')
            ->map(static fn ($id): int => (int) $id);

        $highRiskCustomers = $serviceRiskCustomerIds
            ->merge($financeRiskCustomerIds)
            ->unique()
            ->count();

        $conversationStoreIds = $this->storeIdsForPermissions($actor, ['whatsapp.inbox.view']);
        $unresolvedConversations = $conversationStoreIds === []
            ? null
            : DB::table('whats_app_conversations')
                ->whereIn('store_id', $conversationStoreIds)
                ->whereNull('resolved_at')
                ->whereNotIn('status', ['RESOLVED', 'CLOSED'])
                ->count();

        $recentComplaints = (clone $caseBase)
            ->where('type', 'complaint')
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        $lastRefresh = now()->format('Y-m-d H:i');

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'last_refresh' => $lastRefresh,
            'kpis' => [
                $this->metric('active_customers', $activeCustomers, route('admin.customer-360.index', ['active' => 1]), $lastRefresh),
                $this->metric('new_customers', $newCustomers, route('admin.customer-360.index', ['date_from' => $from->toDateString(), 'date_to' => $to->toDateString()]), $lastRefresh),
                $this->metric('repeat_customers', $repeatCustomers, route('admin.erp.customer-service', ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'customer_segment' => 'repeat']), $lastRefresh),
                $this->metric('open_cases', $openCases, route('admin.erp.customer-service', ['status' => 'open']), $lastRefresh),
                $this->metric('overdue_sla', $overdueCases, route('admin.erp.customer-service', ['sla' => 'overdue']), $lastRefresh),
                $this->metric('high_risk_customers', $highRiskCustomers, route('admin.erp.customer-service', ['risk' => 'high']), $lastRefresh),
                $this->metric('unresolved_conversations', $unresolvedConversations, route('admin.whatsapp-inbox.index'), $lastRefresh),
                $this->metric('recent_complaints', $recentComplaints, route('admin.erp.customer-service', ['type' => 'complaint']), $lastRefresh),
            ],
            'charts' => [
                'customer_growth' => $this->customerGrowth($storeIds, $from, $to),
                'case_status' => $this->caseDistribution($storeIds, 'status'),
                'sla' => $this->slaDistribution($storeIds),
                'category' => $this->caseDistribution($storeIds, 'type'),
                'store_mix' => $this->storeMix($storeIds),
                'customer_value' => $this->customerValueByCurrency($storeIds, $from, $to),
            ],
            'exceptions' => $this->exceptions($storeIds),
        ];
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return LengthAwarePaginator<int,object>
     */
    public function paginateCases(User $actor, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $storeIds = $this->viewStoreIds($actor);
        abort_if($storeIds === [], 403);

        $query = $this->caseListQuery($storeIds);
        $this->applyCaseFilters($query, $filters, $actor);

        return $query
            ->orderByRaw("CASE WHEN cases.status IN ('open','pending') AND cases.sla_target_at IS NOT NULL AND cases.sla_target_at < ? THEN 0 ELSE 1 END", [now()])
            ->orderByDesc('cases.updated_at')
            ->orderByDesc('cases.id')
            ->paginate(max(10, min(100, $perPage)))
            ->withQueryString();
    }

    public function caseDetail(User $actor, int $caseId): object
    {
        $storeIds = $this->viewStoreIds($actor);
        $case = $this->caseListQuery($storeIds)->where('cases.id', $caseId)->first();
        abort_unless($case !== null, 404);

        $case->notes = DB::table('customer_service_case_notes as notes')
            ->leftJoin('users as authors', 'authors.id', '=', 'notes.author_user_id')
            ->where('notes.case_id', $caseId)
            ->orderBy('notes.id')
            ->get([
                'notes.id',
                'notes.body',
                'notes.original_name',
                'notes.mime_type',
                'notes.size_bytes',
                'notes.created_at',
                'authors.name as author_name',
            ]);

        return $case;
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    public function createCase(User $actor, array $attributes): CustomerServiceCase
    {
        $manageStoreIds = $this->manageStoreIds($actor);
        abort_if($manageStoreIds === [], 403);

        $data = Validator::make($attributes, [
            'store_id' => ['required', 'integer', Rule::in($manageStoreIds)],
            'platform_customer_id' => ['required', 'integer', 'min:1'],
            'conversation_id' => ['nullable', 'integer', 'min:1'],
            'order_id' => ['nullable', 'integer', 'min:1'],
            'invoice_id' => ['nullable', 'integer', 'min:1'],
            'delivery_reference' => ['nullable', 'string', 'max:120'],
            'type' => ['required', Rule::in((array) config('customer_service.categories', []))],
            'priority' => ['required', Rule::in((array) config('customer_service.priorities', []))],
            'queue' => ['nullable', Rule::in((array) config('customer_service.queues', []))],
            'subject' => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:8000'],
            'assigned_agent_id' => ['nullable', 'integer', 'min:1'],
            'sla_target_at' => ['nullable', 'date'],
        ])->validate();

        $storeId = (int) $data['store_id'];
        $customerId = (int) $data['platform_customer_id'];
        $customer = $this->assertCustomerVisibleInStore($customerId, $storeId);
        $this->assertRelatedRecords($customer, $storeId, $data);

        $assignedAgentId = isset($data['assigned_agent_id']) ? (int) $data['assigned_agent_id'] : null;
        if ($assignedAgentId !== null) {
            $this->assertAssignableAgent($assignedAgentId, $storeId);
        }

        $priority = (string) $data['priority'];
        $slaTarget = isset($data['sla_target_at'])
            ? CarbonImmutable::parse((string) $data['sla_target_at'])
            : CarbonImmutable::now()->addHours($this->slaHours($priority));

        $case = CustomerServiceCase::query()->create([
            'public_id' => (string) Str::uuid(),
            'store_id' => $storeId,
            'platform_customer_id' => $customerId,
            'conversation_id' => $data['conversation_id'] ?? null,
            'order_id' => $data['order_id'] ?? null,
            'invoice_id' => $data['invoice_id'] ?? null,
            'delivery_reference' => $data['delivery_reference'] ?? null,
            'type' => $data['type'],
            'status' => 'open',
            'priority' => $priority,
            'queue' => $data['queue'] ?? 'general',
            'subject' => $data['subject'],
            'description' => $data['description'] ?? null,
            'assigned_agent_id' => $assignedAgentId,
            'created_by' => $actor->getKey(),
            'updated_by' => $actor->getKey(),
            'sla_target_at' => $slaTarget,
            'source' => 'agent',
        ]);

        $this->audit->record('customer_service.case.created', $actor, $case, null, [
            'store_id' => $storeId,
            'case_id' => $case->getKey(),
            'platform_customer_id' => $customerId,
            'status' => 'open',
            'priority' => $priority,
            'queue' => $case->queue,
            'sla_target_at' => $slaTarget->toIso8601String(),
        ]);

        return $case;
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    public function updateCase(User $actor, int $caseId, array $attributes): CustomerServiceCase
    {
        $case = $this->managedCase($actor, $caseId);

        $data = Validator::make($attributes, [
            'status' => ['nullable', Rule::in((array) config('customer_service.statuses', []))],
            'priority' => ['nullable', Rule::in((array) config('customer_service.priorities', []))],
            'queue' => ['nullable', Rule::in((array) config('customer_service.queues', []))],
            'type' => ['nullable', Rule::in((array) config('customer_service.categories', []))],
            'subject' => ['nullable', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:8000'],
            'assigned_agent_id' => ['nullable', 'integer', 'min:1'],
            'sla_target_at' => ['nullable', 'date'],
            'service_recovery_code' => ['nullable', 'string', 'max:64'],
            'service_recovery_note' => ['nullable', 'string', 'max:4000'],
            'delivery_reference' => ['nullable', 'string', 'max:120'],
        ])->validate();

        $before = $case->only([
            'status', 'priority', 'queue', 'type', 'subject', 'assigned_agent_id',
            'sla_target_at', 'escalated_at', 'resolved_at', 'service_recovery_code',
        ]);

        if (array_key_exists('assigned_agent_id', $data) && $data['assigned_agent_id'] !== null) {
            $this->assertAssignableAgent((int) $data['assigned_agent_id'], (int) $case->store_id);
        }

        if (isset($data['priority']) && ! array_key_exists('sla_target_at', $data) && in_array($case->status, ['open', 'pending'], true)) {
            $data['sla_target_at'] = CarbonImmutable::now()->addHours($this->slaHours((string) $data['priority']));
        }

        if (array_key_exists('status', $data)) {
            $nextStatus = (string) $data['status'];
            if (in_array($nextStatus, ['resolved', 'closed'], true) && $case->resolved_at === null) {
                $data['resolved_at'] = now();
            } elseif (in_array($nextStatus, ['open', 'pending'], true)) {
                $data['resolved_at'] = null;
            }
        }

        if (
            in_array((string) ($data['status'] ?? $case->status), ['open', 'pending'], true)
            && ($data['sla_target_at'] ?? $case->sla_target_at) !== null
            && CarbonImmutable::parse((string) ($data['sla_target_at'] ?? $case->sla_target_at))->isPast()
            && $case->escalated_at === null
        ) {
            $data['escalated_at'] = now();
        }

        $data['updated_by'] = $actor->getKey();
        $case->fill($data);
        $case->save();

        $this->audit->record('customer_service.case.updated', $actor, $case, $before, [
            'store_id' => (int) $case->store_id,
            ...$case->only([
                'status', 'priority', 'queue', 'type', 'subject', 'assigned_agent_id',
                'sla_target_at', 'escalated_at', 'resolved_at', 'service_recovery_code',
            ]),
        ]);

        return $case->refresh();
    }

    public function addNote(
        User $actor,
        int $caseId,
        ?string $body,
        ?UploadedFile $attachment = null,
    ): int {
        $case = $this->managedCase($actor, $caseId);
        $body = $body === null ? null : trim($body);

        if (($body === null || $body === '') && ! $attachment instanceof UploadedFile) {
            throw ValidationException::withMessages(['body' => ['A note or attachment is required.']]);
        }

        $storagePath = null;
        $originalName = null;
        $mimeType = null;
        $sizeBytes = null;

        if ($attachment instanceof UploadedFile) {
            $extension = strtolower((string) ($attachment->guessExtension() ?: $attachment->getClientOriginalExtension()));
            $filename = Str::uuid()->toString().($extension !== '' ? '.'.$extension : '');
            $directory = 'customer-service/cases/'.$case->public_id;
            $storagePath = Storage::disk('local')->putFileAs($directory, $attachment, $filename);
            if (! is_string($storagePath) || $storagePath === '' || ! Storage::disk('local')->exists($storagePath)) {
                throw ValidationException::withMessages(['attachment' => ['The attachment could not be stored safely.']]);
            }
            $originalName = Str::limit($attachment->getClientOriginalName(), 255, '');
            $mimeType = $attachment->getMimeType() ?: null;
            $sizeBytes = (int) $attachment->getSize();
        }

        try {
            $noteId = (int) DB::table('customer_service_case_notes')->insertGetId([
                'case_id' => $case->getKey(),
                'author_user_id' => $actor->getKey(),
                'body' => $body === '' ? null : $body,
                'storage_path' => $storagePath,
                'original_name' => $originalName,
                'mime_type' => $mimeType,
                'size_bytes' => $sizeBytes,
                'is_internal' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            if (is_string($storagePath)) {
                Storage::disk('local')->delete($storagePath);
            }
            throw $exception;
        }

        $this->audit->record('customer_service.case.note_added', $actor, $case, null, [
            'store_id' => (int) $case->store_id,
            'case_id' => $case->getKey(),
            'note_id' => $noteId,
            'has_attachment' => $storagePath !== null,
        ]);

        return $noteId;
    }

    public function attachment(User $actor, int $caseId, int $noteId): object
    {
        $this->caseDetail($actor, $caseId);

        $note = DB::table('customer_service_case_notes')
            ->where('case_id', $caseId)
            ->where('id', $noteId)
            ->whereNotNull('storage_path')
            ->first();
        abort_unless($note !== null, 404);
        abort_unless(Storage::disk('local')->exists((string) $note->storage_path), 404);

        return $note;
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    public function recordFeedback(User $actor, int $caseId, array $attributes): CustomerServiceCase
    {
        $case = $this->managedCase($actor, $caseId);
        abort_unless(in_array($case->status, ['resolved', 'closed'], true), 409);

        $data = Validator::make($attributes, [
            'csat_score' => ['nullable', 'integer', 'min:1', 'max:5'],
            'nps_score' => ['nullable', 'integer', 'min:0', 'max:10'],
            'feedback_context' => ['required', 'string', 'max:190'],
            'consent' => ['accepted'],
        ])->validate();

        if (! isset($data['csat_score']) && ! isset($data['nps_score'])) {
            throw ValidationException::withMessages(['csat_score' => ['CSAT or NPS is required.']]);
        }

        $before = $case->only(['csat_score', 'nps_score', 'feedback_consent_at', 'feedback_context']);
        $case->fill([
            'csat_score' => $data['csat_score'] ?? null,
            'nps_score' => $data['nps_score'] ?? null,
            'feedback_consent_at' => now(),
            'feedback_context' => $data['feedback_context'],
            'updated_by' => $actor->getKey(),
        ]);
        $case->save();

        $this->audit->record('customer_service.case.feedback_recorded', $actor, $case, $before, [
            'store_id' => (int) $case->store_id,
            ...$case->only(['csat_score', 'nps_score', 'feedback_consent_at', 'feedback_context']),
        ]);

        return $case->refresh();
    }

    /**
     * @param  list<int>  $requestedStoreIds
     * @return Collection<int,object>
     */
    public function customerCases(User $actor, int $platformCustomerId, array $requestedStoreIds): Collection
    {
        $allowed = array_values(array_intersect($requestedStoreIds, $this->viewStoreIds($actor)));
        if ($allowed === []) {
            return collect();
        }

        return $this->caseListQuery($allowed)
            ->where('cases.platform_customer_id', $platformCustomerId)
            ->orderByDesc('cases.updated_at')
            ->limit(50)
            ->get();
    }

    public function canManageStore(User $actor, int $storeId): bool
    {
        return in_array($storeId, $this->manageStoreIds($actor), true);
    }

    /** @return Collection<int,object> */
    public function storeOptions(User $actor, bool $manage = false): Collection
    {
        $ids = $manage ? $this->manageStoreIds($actor) : $this->viewStoreIds($actor);

        return $ids === []
            ? collect()
            : DB::table('stores')->whereIn('id', $ids)->orderBy('name')->get(['id', 'name', 'code']);
    }

    /** @return Collection<int,object> */
    public function customerOptions(User $actor, ?string $search = null, int $limit = 50): Collection
    {
        $storeIds = $this->viewStoreIds($actor);
        if ($storeIds === []) {
            return collect();
        }

        $query = $this->visibleCustomersQuery($storeIds);
        $term = trim((string) $search);
        if ($term !== '') {
            $needle = '%'.addcslashes($term, '%_\\').'%';
            $query->where(function ($searchQuery) use ($needle): void {
                $searchQuery->where('pc.name', 'like', $needle)
                    ->orWhere('pc.email', 'like', $needle)
                    ->orWhere('pc.phone', 'like', $needle);
            });
        }

        return $query
            ->distinct()
            ->orderBy('pc.name')
            ->limit(max(1, min(100, $limit)))
            ->get(['pc.id', 'pc.name', 'pc.email', 'pc.phone', 'pc.origin_store_id']);
    }

    /** @return Collection<int,object> */
    public function agentOptions(User $actor): Collection
    {
        $storeIds = $this->manageStoreIds($actor);
        if ($storeIds === []) {
            return collect();
        }

        return User::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->filter(function (User $agent) use ($storeIds): bool {
                if ($agent->hasRole('SUPER_ADMIN')) {
                    return true;
                }

                foreach ($storeIds as $storeId) {
                    if (
                        $agent->hasPermission('customers.view', $storeId)
                        || $agent->hasPermission('whatsapp.inbox.view', $storeId)
                    ) {
                        return true;
                    }
                }

                return false;
            })
            ->values();
    }

    /**
     * @return array{
     *   orders:Collection<int,object>,
     *   invoices:Collection<int,object>,
     *   conversations:Collection<int,object>
     * }
     */
    public function relatedRecordOptions(User $actor, int $limit = 100): array
    {
        $storeIds = $this->viewStoreIds($actor);
        if ($storeIds === []) {
            return ['orders' => collect(), 'invoices' => collect(), 'conversations' => collect()];
        }

        $limit = max(10, min(200, $limit));

        $orders = DB::table('orders as records')
            ->join('platform_customers as customers', 'customers.legacy_customer_id', '=', 'records.customer_id')
            ->join('stores', 'stores.id', '=', 'records.store_id')
            ->whereIn('records.store_id', $storeIds)
            ->orderByDesc('records.id')
            ->limit($limit)
            ->get([
                'records.id',
                'records.order_number as reference',
                'records.store_id',
                'customers.id as platform_customer_id',
                'customers.name as customer_name',
                'stores.name as store_name',
            ]);

        $invoices = DB::table('invoices as records')
            ->join('platform_customers as customers', function ($join): void {
                $join->on('customers.id', '=', 'records.platform_customer_id')
                    ->orOn('customers.legacy_customer_id', '=', 'records.customer_id');
            })
            ->join('stores', 'stores.id', '=', 'records.store_id')
            ->whereIn('records.store_id', $storeIds)
            ->orderByDesc('records.id')
            ->limit($limit)
            ->get([
                'records.id',
                'records.invoice_number as reference',
                'records.store_id',
                'customers.id as platform_customer_id',
                'customers.name as customer_name',
                'stores.name as store_name',
            ]);

        $conversations = DB::table('whats_app_conversations as records')
            ->join('platform_customers as customers', 'customers.id', '=', 'records.platform_customer_id')
            ->join('stores', 'stores.id', '=', 'records.store_id')
            ->whereIn('records.store_id', $storeIds)
            ->orderByDesc('records.id')
            ->limit($limit)
            ->get([
                'records.id',
                'records.public_id as reference',
                'records.store_id',
                'customers.id as platform_customer_id',
                'customers.name as customer_name',
                'stores.name as store_name',
            ]);

        return compact('orders', 'invoices', 'conversations');
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function reportForStore(
        string $report,
        int $storeId,
        array $filters = [],
        int $limit = 5000,
        int $offset = 0,
    ): array {
        abort_unless(DB::table('stores')->where('id', $storeId)->where('is_active', true)->exists(), 404);

        [$from, $to] = $this->dateWindow($filters['from'] ?? null, $filters['to'] ?? null);
        $limit = max(1, min(5000, $limit));
        $offset = max(0, $offset);

        $rows = match ($report) {
            'case_sla' => $this->caseSlaReport($storeId, $from, $to),
            'feedback' => $this->feedbackReport($storeId, $from, $to),
            'customer_growth' => $this->customerGrowthReport($storeId, $from, $to),
            'recovery' => $this->recoveryReport($storeId, $from, $to),
            'workload' => $this->workloadReport($storeId, $from, $to),
            default => throw ValidationException::withMessages(['report' => ['Unsupported customer-service report.']]),
        };

        return [
            'rows' => array_slice($rows, $offset, $limit),
            'total' => count($rows),
        ];
    }

    /** @param list<int> $storeIds */
    private function caseListQuery(array $storeIds)
    {
        return DB::table('customer_service_cases as cases')
            ->leftJoin('platform_customers as customers', 'customers.id', '=', 'cases.platform_customer_id')
            ->leftJoin('stores', 'stores.id', '=', 'cases.store_id')
            ->leftJoin('users as agents', 'agents.id', '=', 'cases.assigned_agent_id')
            ->leftJoin('whats_app_conversations as conversations', 'conversations.id', '=', 'cases.conversation_id')
            ->leftJoin('orders', 'orders.id', '=', 'cases.order_id')
            ->leftJoin('invoices', 'invoices.id', '=', 'cases.invoice_id')
            ->whereIn('cases.store_id', $storeIds)
            ->select([
                'cases.*',
                'customers.name as customer_name',
                'customers.email as customer_email',
                'customers.phone as customer_phone',
                'stores.name as store_name',
                'stores.code as store_code',
                'agents.name as assigned_agent_name',
                'conversations.public_id as conversation_public_id',
                'orders.order_number',
                'invoices.invoice_number',
            ]);
    }

    /**
     * @param  array<string,mixed>  $filters
     */
    private function applyCaseFilters($query, array $filters, User $actor): void
    {
        if (! empty($filters['store_id'])) {
            $query->where('cases.store_id', (int) $filters['store_id']);
        }
        foreach (['status', 'priority', 'queue', 'type'] as $field) {
            if (! empty($filters[$field])) {
                $query->where('cases.'.$field, $filters[$field]);
            }
        }
        if (($filters['sla'] ?? null) === 'overdue') {
            $query->whereIn('cases.status', ['open', 'pending'])
                ->whereNotNull('cases.sla_target_at')
                ->where('cases.sla_target_at', '<', now());
        }
        if (($filters['assigned'] ?? null) === 'mine') {
            $query->where('cases.assigned_agent_id', $actor->getKey());
        } elseif (($filters['assigned'] ?? null) === 'unassigned') {
            $query->whereNull('cases.assigned_agent_id');
        }
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $needle = '%'.addcslashes($q, '%_\\').'%';
            $query->where(function ($search) use ($needle): void {
                $search->where('cases.subject', 'like', $needle)
                    ->orWhere('customers.name', 'like', $needle)
                    ->orWhere('customers.email', 'like', $needle)
                    ->orWhere('customers.phone', 'like', $needle)
                    ->orWhere('orders.order_number', 'like', $needle)
                    ->orWhere('invoices.invoice_number', 'like', $needle);
            });
        }
    }

    private function managedCase(User $actor, int $caseId): CustomerServiceCase
    {
        $manageStoreIds = $this->manageStoreIds($actor);
        abort_if($manageStoreIds === [], 403);

        $case = CustomerServiceCase::query()
            ->whereKey($caseId)
            ->whereIn('store_id', $manageStoreIds)
            ->first();
        abort_unless($case instanceof CustomerServiceCase, 404);

        return $case;
    }

    private function assertCustomerVisibleInStore(int $platformCustomerId, int $storeId): PlatformCustomer
    {
        $customer = PlatformCustomer::query()->find($platformCustomerId);
        abort_unless($customer instanceof PlatformCustomer, 404);

        $visible = (int) ($customer->origin_store_id ?? 0) === $storeId
            || DB::table('whats_app_conversations')
                ->where('platform_customer_id', $platformCustomerId)
                ->where('store_id', $storeId)
                ->exists()
            || DB::table('orders')
                ->where('customer_id', $customer->legacy_customer_id)
                ->where('store_id', $storeId)
                ->exists()
            || DB::table('invoices')
                ->where('store_id', $storeId)
                ->where(function ($query) use ($platformCustomerId, $customer): void {
                    $query->where('platform_customer_id', $platformCustomerId)
                        ->orWhere('customer_id', $customer->legacy_customer_id);
                })
                ->exists();

        abort_unless($visible, 404);

        return $customer;
    }

    /** @param array<string,mixed> $data */
    private function assertRelatedRecords(PlatformCustomer $customer, int $storeId, array $data): void
    {
        if (! empty($data['conversation_id'])) {
            $conversation = DB::table('whats_app_conversations')->where('id', $data['conversation_id'])->first();
            if (
                $conversation === null
                || (int) ($conversation->store_id ?? 0) !== $storeId
                || ($conversation->platform_customer_id !== null && (int) $conversation->platform_customer_id !== (int) $customer->getKey())
            ) {
                throw ValidationException::withMessages(['conversation_id' => ['Conversation is outside the selected customer/store scope.']]);
            }
        }

        if (! empty($data['order_id'])) {
            $valid = DB::table('orders')
                ->where('id', $data['order_id'])
                ->where('store_id', $storeId)
                ->where('customer_id', $customer->legacy_customer_id)
                ->exists();
            if (! $valid) {
                throw ValidationException::withMessages(['order_id' => ['Order is outside the selected customer/store scope.']]);
            }
        }

        if (! empty($data['invoice_id'])) {
            $valid = DB::table('invoices')
                ->where('id', $data['invoice_id'])
                ->where('store_id', $storeId)
                ->where(function ($query) use ($customer): void {
                    $query->where('platform_customer_id', $customer->getKey())
                        ->orWhere('customer_id', $customer->legacy_customer_id);
                })
                ->exists();
            if (! $valid) {
                throw ValidationException::withMessages(['invoice_id' => ['Invoice is outside the selected customer/store scope.']]);
            }
        }
    }

    private function assertAssignableAgent(int $agentId, int $storeId): void
    {
        $agent = User::query()->whereKey($agentId)->where('is_active', true)->first();
        if (
            ! $agent instanceof User
            || ! (
                $agent->hasRole('SUPER_ADMIN')
                || $agent->hasPermission('customers.view', $storeId)
                || $agent->hasPermission('whatsapp.inbox.view', $storeId)
            )
        ) {
            throw ValidationException::withMessages(['assigned_agent_id' => ['Agent is not active or authorized for this store.']]);
        }
    }

    /** @param list<string> $permissions
     * @return list<int>
     */
    private function storeIdsForPermissions(User $actor, array $permissions): array
    {
        if ($actor->hasRole('SUPER_ADMIN')) {
            return DB::table('stores')->where('is_active', true)->orderBy('id')->pluck('id')
                ->map(static fn ($id): int => (int) $id)->all();
        }

        $ids = collect();
        foreach ($permissions as $permission) {
            $ids = $ids->merge($this->tenantScope->allowedStoreIds($actor, $permission));
        }

        $activeStoreIds = DB::table('stores')->where('is_active', true)->pluck('id')
            ->map(static fn ($id): int => (int) $id);
        foreach ($activeStoreIds as $storeId) {
            foreach ($permissions as $permission) {
                if ($actor->hasPermission($permission, $storeId)) {
                    $ids->push($storeId);
                    break;
                }
            }
        }

        return $ids->map(static fn ($id): int => (int) $id)->unique()->sort()->values()->all();
    }

    /** @param list<int> $storeIds */
    private function visibleCustomersQuery(array $storeIds)
    {
        return DB::table('platform_customers as pc')
            ->where(function ($visible) use ($storeIds): void {
                $visible->whereIn('pc.origin_store_id', $storeIds)
                    ->orWhereExists(function ($query) use ($storeIds): void {
                        $query->selectRaw('1')
                            ->from('whats_app_conversations as wc')
                            ->whereColumn('wc.platform_customer_id', 'pc.id')
                            ->whereIn('wc.store_id', $storeIds);
                    })
                    ->orWhereExists(function ($query) use ($storeIds): void {
                        $query->selectRaw('1')
                            ->from('orders as o')
                            ->whereColumn('o.customer_id', 'pc.legacy_customer_id')
                            ->whereIn('o.store_id', $storeIds);
                    })
                    ->orWhereExists(function ($query) use ($storeIds): void {
                        $query->selectRaw('1')
                            ->from('invoices as i')
                            ->whereIn('i.store_id', $storeIds)
                            ->where(function ($customer): void {
                                $customer->whereColumn('i.platform_customer_id', 'pc.id')
                                    ->orWhereColumn('i.customer_id', 'pc.legacy_customer_id');
                            });
                    });
            });
    }

    /** @return array{0:CarbonImmutable,1:CarbonImmutable} */
    private function dateWindow(mixed $from, mixed $to): array
    {
        $end = $to === null || $to === '' ? CarbonImmutable::today() : CarbonImmutable::parse((string) $to);
        $start = $from === null || $from === '' ? $end->subDays(29) : CarbonImmutable::parse((string) $from);

        if ($start->greaterThan($end) || $start->diffInDays($end) > 366) {
            throw ValidationException::withMessages(['from' => ['Date range must be ordered and no longer than 366 days.']]);
        }

        return [$start, $end];
    }

    /** @return array<string,mixed> */
    private function metric(string $key, int|float|string|null $value, string $url, string $lastRefresh): array
    {
        return [
            'key' => $key,
            'value' => $value,
            'url' => $url,
            'last_refresh' => $lastRefresh,
        ];
    }

    /** @param list<int> $storeIds
     * @return list<array{label:string,new:int,repeat:int}>
     */
    private function customerGrowth(array $storeIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $registrations = $this->visibleCustomersQuery($storeIds)
            ->whereBetween('pc.registered_at', [$from->startOfDay(), $to->endOfDay()])
            ->get(['pc.id', 'pc.registered_at']);

        $orders = DB::table('orders as o')
            ->join('platform_customers as pc', 'pc.legacy_customer_id', '=', 'o.customer_id')
            ->whereIn('o.store_id', $storeIds)
            ->whereBetween('o.created_at', [$from->startOfDay(), $to->endOfDay()])
            ->get(['pc.id as customer_id', 'o.created_at'])
            ->groupBy('customer_id');

        $days = max(1, $from->diffInDays($to) + 1);
        $bucket = $days <= 62 ? 'day' : 'month';
        $groups = [];

        foreach ($registrations as $registration) {
            $date = CarbonImmutable::parse((string) $registration->registered_at);
            $key = $bucket === 'day' ? $date->format('Y-m-d') : $date->format('Y-m');
            $groups[$key] ??= ['label' => $key, 'new' => 0, 'repeat' => 0];
            $groups[$key]['new']++;
        }

        foreach ($orders as $customerOrders) {
            if ($customerOrders->count() < 2) {
                continue;
            }
            $date = CarbonImmutable::parse((string) $customerOrders->sortBy('created_at')->last()->created_at);
            $key = $bucket === 'day' ? $date->format('Y-m-d') : $date->format('Y-m');
            $groups[$key] ??= ['label' => $key, 'new' => 0, 'repeat' => 0];
            $groups[$key]['repeat']++;
        }

        ksort($groups);

        return array_values($groups);
    }

    /** @param list<int> $storeIds
     * @return list<array{label:string,value:int}>
     */
    private function caseDistribution(array $storeIds, string $field): array
    {
        return DB::table('customer_service_cases')
            ->whereIn('store_id', $storeIds)
            ->select($field, DB::raw('COUNT(*) as aggregate'))
            ->groupBy($field)
            ->orderByDesc('aggregate')
            ->limit(12)
            ->get()
            ->map(static fn (object $row): array => [
                'label' => (string) ($row->{$field} ?? 'unknown'),
                'value' => (int) $row->aggregate,
            ])
            ->all();
    }

    /** @param list<int> $storeIds
     * @return list<array{label:string,value:int}>
     */
    private function slaDistribution(array $storeIds): array
    {
        $closed = DB::table('customer_service_cases')
            ->whereIn('store_id', $storeIds)
            ->whereNotNull('resolved_at')
            ->whereNotNull('sla_target_at')
            ->get(['resolved_at', 'sla_target_at']);

        $attained = $closed->filter(static fn (object $row): bool => CarbonImmutable::parse((string) $row->resolved_at)->lessThanOrEqualTo(CarbonImmutable::parse((string) $row->sla_target_at))
        )->count();

        return [
            ['label' => 'attained', 'value' => $attained],
            ['label' => 'breached', 'value' => $closed->count() - $attained],
        ];
    }

    /** @param list<int> $storeIds
     * @return list<array{label:string,value:int}>
     */
    private function storeMix(array $storeIds): array
    {
        return DB::table('customer_service_cases as cases')
            ->join('stores', 'stores.id', '=', 'cases.store_id')
            ->whereIn('cases.store_id', $storeIds)
            ->select('stores.name', DB::raw('COUNT(cases.id) as aggregate'))
            ->groupBy('stores.id', 'stores.name')
            ->orderByDesc('aggregate')
            ->limit(12)
            ->get()
            ->map(static fn (object $row): array => ['label' => (string) $row->name, 'value' => (int) $row->aggregate])
            ->all();
    }

    /** @param list<int> $storeIds
     * @return list<array{label:string,value:float}>
     */
    private function customerValueByCurrency(array $storeIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return DB::table('orders')
            ->whereIn('store_id', $storeIds)
            ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->select('currency', DB::raw('SUM(grand_total) as aggregate'))
            ->groupBy('currency')
            ->orderByDesc('aggregate')
            ->get()
            ->map(static fn (object $row): array => [
                'label' => strtoupper((string) $row->currency),
                'value' => round((float) $row->aggregate, 3),
            ])
            ->all();
    }

    /** @param list<int> $storeIds
     * @return list<array<string,mixed>>
     */
    private function exceptions(array $storeIds): array
    {
        return DB::table('customer_service_cases as cases')
            ->leftJoin('platform_customers as customers', 'customers.id', '=', 'cases.platform_customer_id')
            ->leftJoin('users as agents', 'agents.id', '=', 'cases.assigned_agent_id')
            ->whereIn('cases.store_id', $storeIds)
            ->whereIn('cases.status', ['open', 'pending'])
            ->where(function ($query): void {
                $query->whereNull('cases.assigned_agent_id')
                    ->orWhere('cases.priority', 'urgent')
                    ->orWhere(function ($overdue): void {
                        $overdue->whereNotNull('cases.sla_target_at')->where('cases.sla_target_at', '<', now());
                    });
            })
            ->orderBy('cases.sla_target_at')
            ->limit(12)
            ->get([
                'cases.id',
                'cases.subject',
                'cases.status',
                'cases.priority',
                'cases.sla_target_at',
                'customers.name as customer_name',
                'agents.name as assigned_agent_name',
            ])
            ->map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'subject' => $row->subject,
                'customer_name' => $row->customer_name,
                'priority' => $row->priority,
                'status' => $row->status,
                'sla_target_at' => $row->sla_target_at,
                'assigned_agent_name' => $row->assigned_agent_name,
                'url' => route('admin.erp.customer-service.cases.show', ['case' => $row->id]),
            ])
            ->all();
    }

    private function slaHours(string $priority): int
    {
        return max(1, (int) config('customer_service.sla_hours.'.$priority, 24));
    }

    /** @return list<array<string,mixed>> */
    private function caseSlaReport(int $storeId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return DB::table('customer_service_cases')
            ->where('store_id', $storeId)
            ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
            ->orderByDesc('id')
            ->get(['id', 'status', 'priority', 'queue', 'sla_target_at', 'resolved_at', 'created_at'])
            ->map(static function (object $row): array {
                $resolutionHours = $row->resolved_at === null
                    ? null
                    : round(CarbonImmutable::parse((string) $row->created_at)->diffInMinutes(CarbonImmutable::parse((string) $row->resolved_at)) / 60, 2);
                $overdue = $row->sla_target_at !== null
                    && CarbonImmutable::parse((string) ($row->resolved_at ?? now()))->greaterThan(CarbonImmutable::parse((string) $row->sla_target_at));

                return [
                    'case_id' => (int) $row->id,
                    'status' => (string) $row->status,
                    'priority' => (string) $row->priority,
                    'queue' => (string) ($row->queue ?? ''),
                    'cases' => 1,
                    'overdue_cases' => $overdue ? 1 : 0,
                    'resolution_hours' => $resolutionHours ?? 0,
                ];
            })
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function feedbackReport(int $storeId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return DB::table('customer_service_cases')
            ->where('store_id', $storeId)
            ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
            ->where(function ($query): void {
                $query->whereNotNull('csat_score')->orWhereNotNull('nps_score');
            })
            ->orderByDesc('id')
            ->get(['id', 'type', 'queue', 'csat_score', 'nps_score'])
            ->map(static fn (object $row): array => [
                'case_id' => (int) $row->id,
                'category' => (string) $row->type,
                'queue' => (string) ($row->queue ?? ''),
                'responses' => 1,
                'csat' => $row->csat_score === null ? 0 : (int) $row->csat_score,
                'nps' => $row->nps_score === null ? 0 : (int) $row->nps_score,
            ])
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function customerGrowthReport(int $storeId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $customers = $this->visibleCustomersQuery([$storeId])
            ->whereBetween('pc.registered_at', [$from->startOfDay(), $to->endOfDay()])
            ->get(['pc.id', 'pc.legacy_customer_id', 'pc.registered_at']);

        return $customers->map(function (object $customer) use ($storeId, $from, $to): array {
            $orders = DB::table('orders')
                ->where('store_id', $storeId)
                ->where('customer_id', $customer->legacy_customer_id)
                ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()]);
            $count = (clone $orders)->count();
            $value = (float) (clone $orders)->whereNotIn('status', ['cancelled', 'refunded'])->sum('grand_total');

            return [
                'customer_id' => (int) $customer->id,
                'registered_month' => CarbonImmutable::parse((string) $customer->registered_at)->format('Y-m'),
                'new_customers' => 1,
                'repeat_customers' => $count >= 2 ? 1 : 0,
                'order_count' => $count,
                'order_value' => round($value, 3),
            ];
        })->all();
    }

    /** @return list<array<string,mixed>> */
    private function recoveryReport(int $storeId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return DB::table('customer_service_cases')
            ->where('store_id', $storeId)
            ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
            ->where(function ($query): void {
                $query->where('type', 'complaint')->orWhereNotNull('service_recovery_code');
            })
            ->orderByDesc('id')
            ->get(['id', 'type', 'status', 'service_recovery_code'])
            ->map(static fn (object $row): array => [
                'case_id' => (int) $row->id,
                'category' => (string) $row->type,
                'status' => (string) $row->status,
                'recovery_code' => (string) ($row->service_recovery_code ?? ''),
                'complaints' => $row->type === 'complaint' ? 1 : 0,
                'recovery_actions' => $row->service_recovery_code === null ? 0 : 1,
            ])
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function workloadReport(int $storeId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return DB::table('customer_service_cases as cases')
            ->leftJoin('users as agents', 'agents.id', '=', 'cases.assigned_agent_id')
            ->where('cases.store_id', $storeId)
            ->whereBetween('cases.created_at', [$from->startOfDay(), $to->endOfDay()])
            ->select([
                'cases.assigned_agent_id',
                'agents.name as agent_name',
                'cases.queue',
                DB::raw("SUM(CASE WHEN cases.status IN ('open','pending') THEN 1 ELSE 0 END) as open_cases"),
                DB::raw("SUM(CASE WHEN cases.status IN ('open','pending') AND cases.sla_target_at IS NOT NULL AND cases.sla_target_at < CURRENT_TIMESTAMP THEN 1 ELSE 0 END) as overdue_cases"),
                DB::raw('COUNT(cases.id) as total_cases'),
            ])
            ->groupBy('cases.assigned_agent_id', 'agents.name', 'cases.queue')
            ->orderByDesc('open_cases')
            ->get()
            ->map(static fn (object $row): array => [
                'assigned_agent_id' => $row->assigned_agent_id === null ? 0 : (int) $row->assigned_agent_id,
                'agent_name' => (string) ($row->agent_name ?? 'Unassigned'),
                'queue' => (string) ($row->queue ?? ''),
                'open_cases' => (int) $row->open_cases,
                'overdue_cases' => (int) $row->overdue_cases,
                'total_cases' => (int) $row->total_cases,
            ])
            ->all();
    }
}
