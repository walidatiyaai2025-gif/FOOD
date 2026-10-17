<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\CustomerServiceCaseManager;
use App\Support\AdminNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CustomerServiceWorkspaceController extends Controller
{
    public function __construct(
        private readonly AdminNavigation $navigation,
        private readonly CustomerServiceCaseManager $cases,
    ) {}

    public function index(Request $request): View
    {
        return $this->workspace($request);
    }

    public function showCase(Request $request, int $case): View
    {
        return $this->workspace($request, $case);
    }

    public function storeCase(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $case = $this->cases->createCase($actor, $request->all());

        return redirect()
            ->route('admin.erp.customer-service.cases.show', ['case' => $case->getKey()])
            ->with('status', __('customer_service_workspace.case_messages.created'));
    }

    public function updateCase(Request $request, int $case): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->cases->updateCase($actor, $case, $request->all());

        return redirect()
            ->route('admin.erp.customer-service.cases.show', ['case' => $case])
            ->with('status', __('customer_service_workspace.case_messages.updated'));
    }

    public function addNote(Request $request, int $case): RedirectResponse
    {
        $actor = $this->actor($request);
        $validated = $request->validate([
            'body' => ['nullable', 'string', 'max:8000'],
            'attachment' => ['nullable', 'file', 'max:10240', 'mimes:pdf,png,jpg,jpeg,txt,csv'],
        ]);

        $this->cases->addNote(
            $actor,
            $case,
            $validated['body'] ?? null,
            $request->file('attachment'),
        );

        return redirect()
            ->route('admin.erp.customer-service.cases.show', ['case' => $case])
            ->with('status', __('customer_service_workspace.case_messages.note_added'));
    }

    public function recordFeedback(Request $request, int $case): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->cases->recordFeedback($actor, $case, $request->all());

        return redirect()
            ->route('admin.erp.customer-service.cases.show', ['case' => $case])
            ->with('status', __('customer_service_workspace.case_messages.feedback_recorded'));
    }

    public function attachment(Request $request, int $case, int $note): StreamedResponse
    {
        $record = $this->cases->attachment($this->actor($request), $case, $note);

        return Storage::disk('local')->download(
            (string) $record->storage_path,
            (string) ($record->original_name ?: 'attachment'),
            ['Content-Type' => (string) ($record->mime_type ?: 'application/octet-stream')],
        );
    }

    private function workspace(Request $request, ?int $selectedCaseId = null): View
    {
        $actor = $this->actor($request);

        $isSuper = $actor->hasRole('SUPER_ADMIN');
        $canCustomers = $isSuper || $this->navigation->hasAnyPermissionInScope($actor, [
            'customers.view',
            'b2b.accounts.view',
        ]);
        $canOrders = $isSuper || $this->navigation->hasPermissionInScope($actor, 'orders.view');
        $canFinance = $isSuper || $this->navigation->hasPermissionInScope($actor, 'finance.view');
        $canDelivery = $isSuper || $this->navigation->hasAnyPermissionInScope($actor, [
            'field_ops.manage',
            'drivers.b2b.view',
            'drivers.tracking.view',
        ]);
        $canWhatsApp = $isSuper || $this->navigation->hasPermissionInScope($actor, 'whatsapp.inbox.view');
        $viewStoreIds = $this->cases->viewStoreIds($actor);

        abort_unless(
            $canCustomers || $canOrders || $canFinance || $canDelivery || $canWhatsApp || $viewStoreIds !== [],
            403,
        );

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'store_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::in((array) config('customer_service.statuses', []))],
            'priority' => ['nullable', Rule::in((array) config('customer_service.priorities', []))],
            'queue' => ['nullable', Rule::in((array) config('customer_service.queues', []))],
            'type' => ['nullable', Rule::in((array) config('customer_service.categories', []))],
            'sla' => ['nullable', Rule::in(['overdue'])],
            'assigned' => ['nullable', Rule::in(['mine', 'unassigned'])],
            'risk' => ['nullable', Rule::in(['high'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'customer_q' => ['nullable', 'string', 'max:120'],
        ]);

        if (! empty($filters['store_id'])) {
            abort_unless(in_array((int) $filters['store_id'], $viewStoreIds, true), 404);
        }

        if (($filters['risk'] ?? null) === 'high') {
            $filters['priority'] ??= 'high';
        }

        $capabilities = collect([
            [
                'key' => 'customer',
                'visible' => $canCustomers,
                'route' => 'admin.customer-360.index',
                'icon' => '◎',
            ],
            [
                'key' => 'orders',
                'visible' => $canOrders,
                'route' => 'admin.operations.orders.index',
                'icon' => '↻',
            ],
            [
                'key' => 'finance',
                'visible' => $canCustomers && $canFinance,
                'route' => 'admin.customer-360.index',
                'icon' => '¤',
            ],
            [
                'key' => 'delivery',
                'visible' => $canDelivery,
                'route' => 'admin.field-operations.overview',
                'icon' => '⌖',
            ],
            [
                'key' => 'conversations',
                'visible' => $canWhatsApp,
                'route' => 'admin.whatsapp-inbox.index',
                'icon' => '✦',
            ],
            [
                'key' => 'activity',
                'visible' => $canCustomers,
                'route' => 'admin.customer-360.index',
                'icon' => '≡',
            ],
        ])->filter(
            fn (array $item): bool => $item['visible'] === true && Route::has($item['route'])
        )->map(
            fn (array $item): array => $item + ['url' => route($item['route'])]
        )->values();

        $selectedCaseId ??= $request->integer('case_id') ?: null;

        return view('admin.customer-service-workspace', [
            'user' => $actor,
            'navGroups' => $this->navigation->groupsFor($actor),
            'navContext' => 'customer_360',
            'capabilities' => $capabilities,
            'canCustomers' => $canCustomers && Route::has('admin.customer-360.index'),
            'dashboard' => $this->cases->dashboard($actor, $filters),
            'cases' => $this->cases->paginateCases($actor, $filters),
            'selectedCase' => $selectedCaseId === null ? null : $this->cases->caseDetail($actor, $selectedCaseId),
            'filters' => $filters,
            'storeOptions' => $this->cases->storeOptions($actor),
            'manageStoreOptions' => $this->cases->storeOptions($actor, true),
            'customerOptions' => $this->cases->customerOptions($actor, $filters['customer_q'] ?? $filters['q'] ?? null),
            'agentOptions' => $this->cases->agentOptions($actor),
            'canManageCases' => $this->cases->manageStoreIds($actor) !== [],
            'caseStatuses' => (array) config('customer_service.statuses', []),
            'casePriorities' => (array) config('customer_service.priorities', []),
            'caseQueues' => (array) config('customer_service.queues', []),
            'caseCategories' => (array) config('customer_service.categories', []),
        ]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->is_active, 403);

        return $actor;
    }
}
