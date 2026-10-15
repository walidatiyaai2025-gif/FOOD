<?php

namespace App\Http\Controllers\Admin;

use App\Enterprise\Workflow\WorkflowEngine;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\EnterpriseActionCenter;
use App\Support\AdminNavigation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use LogicException;

final class EnterpriseActionCenterController extends Controller
{
    public function __construct(
        private readonly EnterpriseActionCenter $actionCenter,
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
        ]);

        $search = trim((string) ($validated['q'] ?? ''));
        $state = (string) ($validated['state'] ?? 'all');
        $page = (int) ($validated['actions_page'] ?? 1);

        $result = $this->actionCenter->page(
            $actor,
            $search,
            $state,
            $page,
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

        $query = array_filter([
            'q' => trim((string) ($validated['q'] ?? '')),
            'state' => (string) ($validated['state'] ?? 'all'),
            'actions_page' => (int) ($validated['actions_page'] ?? 1),
        ], static fn ($value, $key): bool => match ($key) {
            'q' => $value !== '',
            'state' => $value !== 'all',
            'actions_page' => $value > 1,
            default => false,
        }, ARRAY_FILTER_USE_BOTH);

        return redirect()
            ->route('admin.action-center.index', $query)
            ->with('status', __('enterprise_action_center.decision_recorded'));
    }
}
