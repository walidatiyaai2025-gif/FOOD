<?php

namespace App\Http\Controllers\Admin;

use App\Enterprise\Workflow\WorkflowEngine;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\EnterpriseActionCenter;
use App\Services\EnterpriseMyWork;
use App\Services\EnterpriseSavedViewService;
use App\Support\AdminNavigation;
use App\Support\EnterpriseGridState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use LogicException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class EnterpriseActionCenterController extends Controller
{
    public function __construct(
        private readonly EnterpriseActionCenter $actionCenter,
        private readonly EnterpriseMyWork $myWork,
        private readonly EnterpriseSavedViewService $savedViews,
        private readonly WorkflowEngine $workflow,
        private readonly AdminNavigation $navigation,
    ) {}

    public function index(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', Rule::in(['all', 'pending', 'overdue'])],
            'actions_page' => ['nullable', 'integer', 'min:1'],
            'module' => ['nullable', Rule::in(['all', 'procurement', 'wms', 'finance', 'treasury', 'customer_service'])],
            'work_state' => ['nullable', Rule::in(['all', 'pending', 'overdue', 'attention'])],
            'owner' => ['nullable', Rule::in(['all', 'me'])],
            'age' => ['nullable', Rule::in([0, 7, 30, 90])],
            'work_page' => ['nullable', 'integer', 'min:1'],
            'kind' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'max:120'],
            'priority' => ['nullable', Rule::in(['all', 'normal', 'high', 'unconfigured'])],
            'due' => ['nullable', Rule::in(['all', 'today', 'overdue', 'upcoming', 'unconfigured'])],
            'scope' => ['nullable', 'string', 'max:120'],
            'requester' => ['nullable', 'string', 'max:40'],
            'assignee' => ['nullable', 'string', 'max:40'],
            'delegate' => ['nullable', 'string', 'max:40'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $search = trim((string) ($validated['q'] ?? ''));
        $state = (string) ($validated['state'] ?? 'all');
        $page = (int) ($validated['actions_page'] ?? 1);
        $module = (string) ($validated['module'] ?? 'all');
        $workState = (string) ($validated['work_state'] ?? 'all');
        $owner = (string) ($validated['owner'] ?? 'all');
        $age = (int) ($validated['age'] ?? 0);
        $workPage = (int) ($validated['work_page'] ?? 1);
        $workFilters = [
            'kind' => (string) ($validated['kind'] ?? 'all'),
            'status' => (string) ($validated['status'] ?? 'all'),
            'priority' => (string) ($validated['priority'] ?? 'all'),
            'due' => (string) ($validated['due'] ?? 'all'),
            'scope' => (string) ($validated['scope'] ?? 'all'),
            'requester' => (string) ($validated['requester'] ?? 'all'),
            'assignee' => (string) ($validated['assignee'] ?? 'all'),
            'delegate' => (string) ($validated['delegate'] ?? 'all'),
            'from' => (string) ($validated['from'] ?? ''),
            'to' => (string) ($validated['to'] ?? ''),
        ];

        $result = $this->actionCenter->page(
            $actor,
            $search,
            $state,
            $page,
            route('admin.action-center.index'),
        );

        $workResult = $this->myWork->page(
            $actor,
            $search,
            $module,
            $workState,
            $owner,
            $age,
            $workPage,
            route('admin.action-center.index'),
            $workFilters,
        );

        $approvalAll = $this->actionCenter->page(
            $actor,
            '',
            'all',
            1,
            route('admin.action-center.index'),
        );
        $approvalOverdue = $this->actionCenter->page(
            $actor,
            '',
            'overdue',
            1,
            route('admin.action-center.index'),
        );
        $workSummary = $workResult['summary'];
        $workSummary['pending_approval'] = $approvalAll['actions']->total();
        $workSummary['approval_overdue'] = $approvalOverdue['actions']->total();

        $savedViews = collect();
        $savedViewStateToken = null;
        if ($actor->hasPermission('platform.manage')) {
            $grid = EnterpriseGridState::fromRequest(
                $request,
                ['module', 'kind', 'work_state', 'priority', 'due', 'scope', 'requester', 'assignee', 'delegate', 'age'],
                [],
                [],
                [],
            );
            $savedViews = $this->savedViews
                ->visible($actor, 'enterprise.my_work', 'platform', null)
                ->map(fn ($view): array => [
                    'id' => (int) $view->getKey(),
                    'name' => (string) $view->name,
                    'visibility' => (string) $view->visibility,
                    'owned' => (int) $view->user_id === (int) $actor->getKey(),
                    'query' => $this->savedViews->queryFor($view),
                ]);
            $savedViewStateToken = $this->savedViews->stateToken(
                'enterprise.my_work',
                'platform',
                null,
                $grid,
            );
        }

        return view('admin.enterprise-action-center', [
            'user' => $actor,
            'navGroups' => $this->navigation->groupsFor($actor),
            'navContext' => 'enterprise_action_center',
            'actions' => $result['actions'],
            'truncated' => $result['truncated'],
            'search' => $search,
            'state' => $state,
            'workItems' => $workResult['items'],
            'workSummary' => $workSummary,
            'workAnalytics' => $workResult['analytics'],
            'workFilterOptions' => $workResult['filter_options'],
            'activity' => $workResult['activity'],
            'module' => $module,
            'workState' => $workState,
            'owner' => $owner,
            'age' => $age,
            'workFilters' => $workFilters,
            'savedViews' => $savedViews,
            'savedViewStateToken' => $savedViewStateToken,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'module' => ['nullable', Rule::in(['all', 'procurement', 'wms', 'finance', 'treasury', 'customer_service'])],
            'work_state' => ['nullable', Rule::in(['all', 'pending', 'overdue', 'attention'])],
            'owner' => ['nullable', Rule::in(['all', 'me'])],
            'age' => ['nullable', Rule::in([0, 7, 30, 90])],
            'kind' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'max:120'],
            'priority' => ['nullable', Rule::in(['all', 'normal', 'high', 'unconfigured'])],
            'due' => ['nullable', Rule::in(['all', 'today', 'overdue', 'upcoming', 'unconfigured'])],
            'scope' => ['nullable', 'string', 'max:120'],
            'requester' => ['nullable', 'string', 'max:40'],
            'assignee' => ['nullable', 'string', 'max:40'],
            'delegate' => ['nullable', 'string', 'max:40'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $filters = [
            'kind' => (string) ($validated['kind'] ?? 'all'),
            'status' => (string) ($validated['status'] ?? 'all'),
            'priority' => (string) ($validated['priority'] ?? 'all'),
            'due' => (string) ($validated['due'] ?? 'all'),
            'scope' => (string) ($validated['scope'] ?? 'all'),
            'requester' => (string) ($validated['requester'] ?? 'all'),
            'assignee' => (string) ($validated['assignee'] ?? 'all'),
            'delegate' => (string) ($validated['delegate'] ?? 'all'),
            'from' => (string) ($validated['from'] ?? ''),
            'to' => (string) ($validated['to'] ?? ''),
        ];

        $result = $this->myWork->page(
            $actor,
            trim((string) ($validated['q'] ?? '')),
            (string) ($validated['module'] ?? 'all'),
            (string) ($validated['work_state'] ?? 'all'),
            (string) ($validated['owner'] ?? 'all'),
            (int) ($validated['age'] ?? 0),
            1,
            route('admin.action-center.index'),
            $filters,
        );

        return response()->streamDownload(function () use ($result): void {
            $stream = fopen('php://output', 'w');
            if ($stream === false) {
                return;
            }
            fputcsv($stream, ['module', 'type', 'reference', 'status', 'state', 'priority', 'scope', 'requester', 'assignee', 'delegate', 'due_at', 'updated_at', 'source']);
            foreach ($result['export_rows'] as $item) {
                fputcsv($stream, [
                    (string) $item['module'],
                    (string) $item['kind'],
                    (string) $item['reference'],
                    (string) $item['status'],
                    (string) $item['state'],
                    (string) ($item['priority'] ?? ''),
                    (string) ($item['scope_key'] ?? ''),
                    (string) ($item['requester_name'] ?? ''),
                    (string) ($item['owner_name'] ?? ''),
                    (string) ($item['delegated_to_name'] ?? ''),
                    $item['due_at']?->toIso8601String(),
                    $item['updated_at']?->toIso8601String(),
                    (string) $item['deep_link'],
                ]);
            }
            fclose($stream);
        }, 'foodex-my-work.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function decide(Request $request, int $workflowRequest, string $stepCode): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $validated = $request->validate([
            'decision' => ['required', Rule::in(['APPROVED', 'REJECTED'])],
            'comment' => ['nullable', 'string', 'max:1000'],
            'q' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', Rule::in(['all', 'pending', 'overdue'])],
            'actions_page' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            $this->workflow->decide(
                $workflowRequest,
                $stepCode,
                $actor,
                (string) $validated['decision'],
                isset($validated['comment']) ? trim((string) $validated['comment']) : null,
            );
        } catch (LogicException) {
            return back()->withErrors([
                'decision' => __('enterprise_action_center.stale_decision'),
            ]);
        }

        $query = [];
        $search = trim((string) ($validated['q'] ?? ''));
        $state = (string) ($validated['state'] ?? 'all');
        $page = (int) ($validated['actions_page'] ?? 1);
        if ($search !== '') {
            $query['q'] = $search;
        }
        if ($state !== 'all') {
            $query['state'] = $state;
        }
        if ($page > 1) {
            $query['actions_page'] = $page;
        }

        return redirect()
            ->route('admin.action-center.index', $query)
            ->with('status', __('enterprise_action_center.decision_recorded'));
    }
}
