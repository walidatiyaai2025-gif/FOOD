<?php

namespace App\Services;

use App\Enterprise\Health\ModuleHealthService;
use App\Models\EnterpriseModule;
use App\Models\EnterpriseModuleEntitlement;
use App\Models\EnterpriseOutboxMessage;
use App\Models\EnterpriseWorkflowRequest;
use Illuminate\Contracts\Pagination\Paginator;

final class EnterpriseSystemInspector
{
    private const PAGE_SIZE = 20;

    private const SNAPSHOT_LIMIT = 100;

    private const ENTITLEMENT_MODULE_LIMIT = 100;

    public function __construct(private readonly ModuleHealthService $health) {}

    /**
     * @return array<string,mixed>
     */
    public function page(): array
    {
        return [
            'modules' => $this->modules(),
            'entitlements' => $this->entitlements(),
            'outbox_status_counts' => $this->outboxStatusCounts(),
            'outbox_messages' => $this->outboxPage(),
            'workflow_status_counts' => $this->workflowStatusCounts(),
            'workflow_requests' => $this->workflowPage(),
        ];
    }

    /**
     * Export uses the same allowlisted fields as the UI and applies explicit
     * limits. Secret-bearing payload/context/idempotency/error/claim fields are
     * intentionally never selected.
     *
     * @return array<string,mixed>
     */
    public function snapshot(): array
    {
        return [
            'modules' => $this->modules(),
            'entitlements' => $this->entitlements(),
            'outbox_status_counts' => $this->outboxStatusCounts(),
            'outbox_messages' => EnterpriseOutboxMessage::query()
                ->orderByDesc('id')
                ->limit(self::SNAPSHOT_LIMIT)
                ->get($this->outboxColumns())
                ->map(static fn (EnterpriseOutboxMessage $message): array => [
                    'event_type' => (string) $message->event_type,
                    'status' => (string) $message->status,
                    'attempt_count' => (int) $message->attempt_count,
                    'available_at' => $message->available_at?->toIso8601String(),
                    'failed_at' => $message->failed_at?->toIso8601String(),
                    'created_at' => $message->created_at?->toIso8601String(),
                ])
                ->all(),
            'workflow_status_counts' => $this->workflowStatusCounts(),
            'workflow_requests' => EnterpriseWorkflowRequest::query()
                ->orderByDesc('id')
                ->limit(self::SNAPSHOT_LIMIT)
                ->get($this->workflowColumns())
                ->map(static fn (EnterpriseWorkflowRequest $request): array => [
                    'workflow_key' => (string) $request->workflow_key,
                    'entity_type' => (string) $request->entity_type,
                    'status' => (string) $request->getRawOriginal('status'),
                    'completed_at' => $request->completed_at?->toIso8601String(),
                    'created_at' => $request->created_at?->toIso8601String(),
                ])
                ->all(),
        ];
    }

    /**
     * @return list<array{code:string,category:string,manifest_version:int,lifecycle_state:string,health_status:string,check_count:int}>
     */
    private function modules(): array
    {
        $registrations = EnterpriseModule::query()
            ->get(['code', 'category', 'manifest_version', 'state'])
            ->keyBy('code');

        $modules = [];

        foreach ($this->health->all() as $moduleCode => $report) {
            /** @var EnterpriseModule|null $registration */
            $registration = $registrations->get($moduleCode);
            $rawState = $registration?->getRawOriginal('state');

            $modules[] = [
                'code' => (string) $moduleCode,
                'category' => (string) (
                    $registration?->getAttribute('category')
                    ?? config("enterprise_modules.modules.{$moduleCode}.category", 'platform')
                ),
                'manifest_version' => (int) (
                    $registration?->getAttribute('manifest_version')
                    ?? config("enterprise_modules.modules.{$moduleCode}.version", 0)
                ),
                'lifecycle_state' => is_string($rawState) ? $rawState : 'NOT_INSTALLED',
                'health_status' => $report->status->value,
                'check_count' => count($report->checks),
            ];
        }

        usort(
            $modules,
            static fn (array $left, array $right): int => $left['code'] <=> $right['code'],
        );

        return $modules;
    }

    /**
     * @return array{total:int,enabled:int,by_module:list<array{module_code:string,total:int,enabled:int}>}
     */
    private function entitlements(): array
    {
        $byModule = EnterpriseModuleEntitlement::query()
            ->selectRaw(
                'module_code, COUNT(*) AS total, SUM(CASE WHEN is_enabled = 1 THEN 1 ELSE 0 END) AS enabled',
            )
            ->groupBy('module_code')
            ->orderBy('module_code')
            ->limit(self::ENTITLEMENT_MODULE_LIMIT)
            ->get()
            ->map(static fn (EnterpriseModuleEntitlement $row): array => [
                'module_code' => (string) $row->getAttribute('module_code'),
                'total' => (int) $row->getAttribute('total'),
                'enabled' => (int) $row->getAttribute('enabled'),
            ])
            ->values()
            ->all();

        return [
            'total' => EnterpriseModuleEntitlement::query()->count(),
            'enabled' => EnterpriseModuleEntitlement::query()->where('is_enabled', true)->count(),
            'by_module' => $byModule,
        ];
    }

    /**
     * @return array<string,int>
     */
    private function outboxStatusCounts(): array
    {
        return EnterpriseOutboxMessage::query()
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->orderBy('status')
            ->pluck('aggregate', 'status')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();
    }

    /**
     * @return array<string,int>
     */
    private function workflowStatusCounts(): array
    {
        return EnterpriseWorkflowRequest::query()
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->orderBy('status')
            ->pluck('aggregate', 'status')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();
    }

    private function outboxPage(): Paginator
    {
        return EnterpriseOutboxMessage::query()
            ->orderByDesc('id')
            ->simplePaginate(
                self::PAGE_SIZE,
                $this->outboxColumns(),
                'outbox_page',
            )
            ->withQueryString();
    }

    private function workflowPage(): Paginator
    {
        return EnterpriseWorkflowRequest::query()
            ->orderByDesc('id')
            ->simplePaginate(
                self::PAGE_SIZE,
                $this->workflowColumns(),
                'workflow_page',
            )
            ->withQueryString();
    }

    /** @return list<string> */
    private function outboxColumns(): array
    {
        return [
            'id',
            'event_type',
            'status',
            'attempt_count',
            'available_at',
            'failed_at',
            'created_at',
        ];
    }

    /** @return list<string> */
    private function workflowColumns(): array
    {
        return [
            'id',
            'workflow_key',
            'entity_type',
            'status',
            'completed_at',
            'created_at',
        ];
    }
}
