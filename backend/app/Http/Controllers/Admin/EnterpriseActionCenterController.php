<?php

namespace App\Http\Controllers\Admin;

use App\Enterprise\Workflow\WorkflowEngine;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\EnterpriseActionCenter;
use App\Services\EnterpriseMyWork;
use App\Support\AdminNavigation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use LogicException;

final class EnterpriseActionCenterController extends Controller
{
    public function __construct(
        private readonly EnterpriseActionCenter $actionCenter,
        private readonly EnterpriseMyWork $myWork,
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
        ]);

        $search = trim((string) ($validated['q'] ?? ''));
        $state = (string) ($validated['state'] ?? 'all');
        $page = (int) ($validated['actions_page'] ?? 1);
        $module = (string) ($validated['module'] ?? 'all');
        $workState = (string) ($validated['work_state'] ?? 'all');
        $owner = (string) ($validated['owner'] ?? 'all');
        $age = (int) ($validated['age'] ?? 0);
        $workPage = (int) ($validated['work_page'] ?? 1);

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
        );

        return view('admin.enterprise-action-center', [
            'user' => $actor,
            'navGroups' => $this->navigation->groupsFor($actor),
            'navContext' => 'enterprise_action_center',
            'actions' => $result['actions'],
            'truncated' => $result['truncated'],
            'search' => $search,
            'state' => $state,
            'workItems' => $workResult['items'],
            'workSummary' => $workResult['summary'],
            'activity' => $workResult['activity'],
            'module' => $module,
            'workState' => $workState,
            'owner' => $owner,
            'age' => $age,
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
