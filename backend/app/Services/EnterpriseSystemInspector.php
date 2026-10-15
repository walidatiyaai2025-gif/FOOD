<?php

namespace App\Services;

use App\Enterprise\Health\ModuleHealthService;
use App\Enterprise\Modules\ModuleManifestCatalog;
use App\Enterprise\Modules\ModuleState;
use App\Models\EnterpriseModule;
use App\Models\EnterpriseModuleEntitlement;
use App\Models\EnterpriseOutboxMessage;
use App\Models\EnterpriseWorkflowRequest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final class EnterpriseSystemInspector
{
    private const PAGE_SIZE = 25;

    private const SNAPSHOT_LIMIT = 100;

    private const ENTITLEMENT_LIMIT = 100;

    public function __construct(
        private readonly ModuleManifestCatalog $manifests,
        private readonly ModuleHealthService $health,
    ) {}

    /**
     * @return array{
     *     modules:list<array{code:string,category:string,state:string,health:string,checked_at:string,check_count:int}>,
     *     entitlements:Collection<int,array{module_code:string,scope_type:string,is_enabled:bool,capability_count:int,updated_at:?string}>,
     *     outbox:LengthAwarePaginator,
     *     workflows:LengthAwarePaginator
     * }
     */
    public function page(): array
    {
        return [
            'modules' => $this->modules(),
            'entitlements' => $this->entitlements(),
            'outbox' => $this->outboxPage(),
            'workflows' => $this->workflowPage(),
        ];
    }

    /**
     * Export deliberately uses the same safe-column allowlist as the UI.
     * Payloads, workflow context, idempotency keys, claim tokens, errors and
     * provider metadata never enter the inspector response.
     *
     * @return array<string,mixed>
     */
    public function snapshot(): array
    {
        return [
            'modules' => $this->modules(),
            'entitlements' => $this->entitlements()->values()->all(),
            'outbox' => EnterpriseOutboxMessage::query()
                ->select($this->outboxColumns())
                ->orderByDesc('id')
                ->limit(self::SNAPSHOT_LIMIT)
                ->get()
                ->map(fn (EnterpriseOutboxMessage $message): array => $this->outboxRow($message))
                ->all(),
            'workflows' => EnterpriseWorkflowRequest::query()
                ->select($this->workflowColumns())
                ->orderByDesc('id')
                ->limit(self::SNAPSHOT_LIMIT)
                ->get()
                ->map(fn (EnterpriseWorkflowRequest $request): array => $this->workflowRow($request))
                ->all(),
        ];
    }

    /**
     * @return list<array{code:string,category:string,state:string,health:string,checked_at:string,check_count:int}>
     */
    private function modules(): array
    {
        $manifests = $this->manifests->all();
        $codes = $manifests->keys()->values();
        $registrations = EnterpriseModule::query()
            ->whereIn('code', $codes)
            ->get(['code', 'state'])
            ->keyBy('code');
        $reports = $this->health->all();

        return $manifests->map(function ($manifest) use ($registrations, $reports): array {
            $registration = $registrations->get($manifest->code);
            $state = $registration?->state;
            $report = $reports[$manifest->code];

            return [
                'code' => $manifest->code,
                'category' => $manifest->category,
                'state' => $state?->value ?? ModuleState::NOT_INSTALLED->value,
                'health' => $report->status->value,
                'checked_at' => $report->checkedAt->toIso8601String(),
                'check_count' => count($report->checks),
            ];
        })->values()->all();
    }

    /**
     * @return Collection<int,array{module_code:string,scope_type:string,is_enabled:bool,capability_count:int,updated_at:?string}>
     */
    private function entitlements(): Collection
    {
        return EnterpriseModuleEntitlement::query()
            ->select(['module_code', 'scope_type', 'is_enabled', 'capabilities', 'updated_at'])
            ->orderByDesc('id')
            ->limit(self::ENTITLEMENT_LIMIT)
            ->get()
            ->map(static fn (EnterpriseModuleEntitlement $entitlement): array => [
                'module_code' => (string) $entitlement->module_code,
                'scope_type' => (string) $entitlement->scope_type,
                'is_enabled' => (bool) $entitlement->is_enabled,
                'capability_count' => count((array) $entitlement->capabilities),
                'updated_at' => $entitlement->updated_at?->toIso8601String(),
            ]);
    }

    private function outboxPage(): LengthAwarePaginator
    {
        $paginator = EnterpriseOutboxMessage::query()
            ->select($this->outboxColumns())
            ->orderByDesc('id')
            ->paginate(self::PAGE_SIZE, ['*'], 'enterprise_outbox_page')
            ->withQueryString();

        $paginator->getCollection()->transform(
            fn (EnterpriseOutboxMessage $message): array => $this->outboxRow($message),
        );

        return $paginator;
    }

    private function workflowPage(): LengthAwarePaginator
    {
        $paginator = EnterpriseWorkflowRequest::query()
            ->select($this->workflowColumns())
            ->orderByDesc('id')
            ->paginate(self::PAGE_SIZE, ['*'], 'enterprise_workflow_page')
            ->withQueryString();

        $paginator->getCollection()->transform(
            fn (EnterpriseWorkflowRequest $request): array => $this->workflowRow($request),
        );

        return $paginator;
    }

    /** @return list<string> */
    private function outboxColumns(): array
    {
        return [
            'id',
            'event_id',
            'event_type',
            'event_version',
            'status',
            'attempt_count',
            'available_at',
            'dispatched_at',
            'failed_at',
        ];
    }

    /** @return list<string> */
    private function workflowColumns(): array
    {
        return [
            'id',
            'workflow_key',
            'definition_version',
            'entity_type',
            'scope_type',
            'status',
            'created_at',
            'completed_at',
        ];
    }

    /**
     * @return array<string,int|string|null>
     */
    private function outboxRow(EnterpriseOutboxMessage $message): array
    {
        return [
            'id' => (int) $message->id,
            'event_id' => (string) $message->event_id,
            'event_type' => (string) $message->event_type,
            'event_version' => (int) $message->event_version,
            'status' => (string) $message->status,
            'attempt_count' => (int) $message->attempt_count,
            'available_at' => $message->available_at?->toIso8601String(),
            'dispatched_at' => $message->dispatched_at?->toIso8601String(),
            'failed_at' => $message->failed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string,int|string|null>
     */
    private function workflowRow(EnterpriseWorkflowRequest $request): array
    {
        return [
            'id' => (int) $request->id,
            'workflow_key' => (string) $request->workflow_key,
            'definition_version' => (int) $request->definition_version,
            'entity_type' => (string) $request->entity_type,
            'scope_type' => (string) $request->scope_type,
            'status' => $request->status->value,
            'created_at' => $request->created_at?->toIso8601String(),
            'completed_at' => $request->completed_at?->toIso8601String(),
        ];
    }
}
