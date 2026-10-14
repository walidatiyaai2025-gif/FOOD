<?php

namespace App\Http\Controllers\Admin;

use App\Domain\WhatsApp\CustomerTimelineService;
use App\Domain\WhatsApp\LeadFollowUpService;
use App\Domain\WhatsApp\SupervisorAnalyticsService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OperationalTenantScope;
use App\Support\AdminNavigation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class WhatsAppSupervisorController extends Controller
{
    public function __construct(
        private readonly OperationalTenantScope $scope,
        private readonly AdminNavigation $navigation,
        private readonly SupervisorAnalyticsService $analytics,
        private readonly CustomerTimelineService $timeline,
        private readonly LeadFollowUpService $crm,
    ) {}

    public function index(Request $request): View
    {
        $actor = $this->actor($request);
        $this->authorizeSupervisor($actor);

        $filters = $request->validate([
            'store_id' => ['nullable', 'integer', 'min:1'],
            'agent_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'lead_temperature' => ['nullable', Rule::in(LeadFollowUpService::TEMPERATURES)],
            'customer_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $dashboard = $this->analytics->dashboard($actor, $filters);
        $storeIds = $dashboard['store_ids'];

        $stores = DB::table('stores')
            ->whereIn('id', $storeIds)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        $customerTimeline = collect();
        $timelineCustomer = null;
        if (! empty($filters['customer_id'])) {
            $customerId = (int) $filters['customer_id'];
            $customerTimeline = $this->timeline->forCustomer($actor, $customerId, $storeIds);
            $timelineCustomer = DB::table('platform_customers')
                ->where('id', $customerId)
                ->first(['id', 'name', 'phone', 'email']);
        }

        return view('admin.whatsapp-supervisor', [
            'user' => $actor,
            'navGroups' => $this->navigation->groupsFor($actor),
            'navContext' => 'whatsapp_supervisor',
            'filters' => $filters,
            'dashboard' => $dashboard,
            'stores' => $stores,
            'timeline' => $customerTimeline,
            'timelineCustomer' => $timelineCustomer,
        ]);
    }

    public function saveLead(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->authorizeSupervisor($actor);
        $data = $request->validate([
            'platform_customer_id' => ['required', 'integer', 'min:1', 'exists:platform_customers,id'],
            'store_id' => ['nullable', 'integer', 'min:1', 'exists:stores,id'],
            'conversation_id' => ['nullable', 'integer', 'min:1', 'exists:whats_app_conversations,id'],
            'assigned_agent_id' => ['nullable', 'integer', 'min:1', 'exists:users,id'],
            'temperature' => ['required', Rule::in(LeadFollowUpService::TEMPERATURES)],
            'status' => ['required', Rule::in(LeadFollowUpService::LEAD_STATUSES)],
            'source_type' => ['nullable', 'string', 'max:64'],
            'source_id' => ['nullable', 'string', 'max:191'],
            'source_label' => ['nullable', 'string', 'max:191'],
        ]);

        $leadId = $this->crm->saveLead($actor, $data);

        return back()
            ->with('status', __('whatsapp_supervisor.flash.lead_saved'))
            ->with('lead_id', $leadId);
    }

    public function createFollowUp(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->authorizeSupervisor($actor);
        $data = $request->validate([
            'platform_customer_id' => ['nullable', 'integer', 'min:1', 'exists:platform_customers,id'],
            'lead_id' => ['nullable', 'integer', 'min:1', 'exists:whats_app_leads,id'],
            'conversation_id' => ['nullable', 'integer', 'min:1', 'exists:whats_app_conversations,id'],
            'store_id' => ['nullable', 'integer', 'min:1', 'exists:stores,id'],
            'assigned_agent_id' => ['nullable', 'integer', 'min:1', 'exists:users,id'],
            'title' => ['required', 'string', 'max:191'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'due_at' => ['nullable', 'date'],
            'source_type' => ['nullable', 'string', 'max:64'],
            'source_id' => ['nullable', 'string', 'max:191'],
        ]);

        $this->crm->createFollowUp($actor, $data);

        return back()->with('status', __('whatsapp_supervisor.flash.follow_up_created'));
    }

    public function transitionFollowUp(Request $request, int $followUp): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->authorizeSupervisor($actor);
        $data = $request->validate([
            'status' => ['required', Rule::in(LeadFollowUpService::FOLLOW_UP_STATUSES)],
        ]);

        $this->crm->transitionFollowUp($actor, $followUp, (string) $data['status']);

        return back()->with('status', __('whatsapp_supervisor.flash.follow_up_updated'));
    }

    public function recordOutcome(Request $request, int $conversation): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->authorizeSupervisor($actor);
        $data = $request->validate([
            'outcome_code' => ['required', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);

        $this->crm->recordOutcome(
            $actor,
            $conversation,
            (string) $data['outcome_code'],
            $data['notes'] ?? null,
        );

        return back()->with('status', __('whatsapp_supervisor.flash.outcome_saved'));
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->is_active, 403);

        app()->setLocale(
            in_array($actor->locale, ['ar', 'en'], true)
                ? $actor->locale
                : 'ar',
        );

        return $actor;
    }

    private function authorizeSupervisor(User $actor): void
    {
        $stores = $this->scope->allowedStoreIds($actor, 'whatsapp.conversations.view');

        abort_unless(
            $actor->hasRole('SUPER_ADMIN')
                || ($actor->hasPermission('whatsapp.customer_service.supervise') && $stores !== []),
            403,
        );
    }
}
