<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Assistant\Intelligence\AssistantBriefDispatcher;
use App\Domain\Assistant\Intelligence\AssistantBriefService;
use App\Domain\Assistant\Intelligence\AssistantIntelligenceAuthorization;
use App\Domain\Assistant\Intelligence\AssistantIntelligenceService;
use App\Domain\Assistant\Intelligence\Models\AssistantIntelligenceBrief;
use App\Domain\Assistant\Intelligence\Models\AssistantIntelligenceFinding;
use App\Domain\Assistant\Intelligence\Models\AssistantIntelligenceSubscription;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\AdminNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\Rule;

final class AssistantIntelligenceController extends Controller
{
    public function __construct(
        private readonly AssistantIntelligenceAuthorization $authorization,
        private readonly AssistantIntelligenceService $intelligence,
        private readonly AssistantBriefService $briefs,
        private readonly AssistantBriefDispatcher $dispatcher,
        private readonly AuditLogger $audit,
        private readonly AdminNavigation $navigation,
    ) {}

    public function index(Request $request): View
    {
        $actor = $this->actor($request);
        $this->authorization->assertCanUse($actor);
        App::setLocale(in_array($actor->locale, ['ar', 'en'], true) ? $actor->locale : 'ar');

        $findings = AssistantIntelligenceFinding::query()
            ->where('recipient_user_id', $actor->getKey())
            ->latest('snapshot_at')
            ->latest('id')
            ->paginate(20, ['*'], 'findings_page')
            ->withQueryString();

        $findings->setCollection(
            $findings->getCollection()
                ->filter(fn (AssistantIntelligenceFinding $finding): bool => $this->authorization->canUseFinding(
                    $actor,
                    $finding,
                ))
                ->values(),
        );

        $briefHistory = AssistantIntelligenceBrief::query()
            ->where('user_id', $actor->getKey())
            ->latest('snapshot_at')
            ->paginate(10, ['*'], 'briefs_page')
            ->withQueryString();

        $briefHistory->setCollection(
            $briefHistory->getCollection()
                ->filter(fn (AssistantIntelligenceBrief $brief): bool => $this->dispatcher->canAccess(
                    $actor,
                    $brief,
                ))
                ->values(),
        );

        $subscription = AssistantIntelligenceSubscription::query()
            ->where('user_id', $actor->getKey())
            ->where('scope_key', 'global')
            ->first();

        if (! $subscription instanceof AssistantIntelligenceSubscription) {
            $subscription = new AssistantIntelligenceSubscription([
                'scope_key' => 'global',
                'enabled' => true,
                'daily_enabled' => true,
                'weekly_enabled' => true,
                'minimum_severity' => 'info',
                'delivery_channel' => 'in_app',
                'locale' => in_array($actor->locale, ['ar', 'en'], true) ? $actor->locale : 'ar',
            ]);
        }

        return view('admin.assistant-intelligence', [
            'user' => $actor,
            'navGroups' => $this->navigation->groupsFor($actor),
            'navContext' => 'assistant_intelligence',
            'findings' => $findings,
            'briefHistory' => $briefHistory,
            'subscription' => $subscription,
            'severities' => AssistantIntelligenceService::SEVERITIES,
            'channelLabels' => (array) __('assistant_intelligence.channel'),
            'canOpenSettings' => $actor->hasPermission('settings.view') || $actor->hasPermission('settings.manage'),
        ]);
    }

    public function updateSubscription(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->authorization->assertCanUse($actor);

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'daily_enabled' => ['required', 'boolean'],
            'weekly_enabled' => ['required', 'boolean'],
            'minimum_severity' => ['required', Rule::in(AssistantIntelligenceService::SEVERITIES)],
            'locale' => ['required', Rule::in(['ar', 'en'])],
        ]);

        $before = AssistantIntelligenceSubscription::query()
            ->where('user_id', $actor->getKey())
            ->where('scope_key', 'global')
            ->first()?->toArray();

        $subscription = $this->intelligence->updateGlobalSubscription($actor, [
            'enabled' => (bool) $data['enabled'],
            'daily_enabled' => (bool) $data['daily_enabled'],
            'weekly_enabled' => (bool) $data['weekly_enabled'],
            'minimum_severity' => (string) $data['minimum_severity'],
            'locale' => (string) $data['locale'],
        ]);

        $this->audit->record(
            'assistant.intelligence.subscription_updated',
            $actor,
            $subscription,
            $before,
            $subscription->toArray(),
            $request,
        );

        return back()->with('status', __('assistant_intelligence.subscription_saved'));
    }

    public function generate(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->authorization->assertCanUse($actor);
        $data = $request->validate([
            'cadence' => ['required', Rule::in(['daily', 'weekly'])],
        ]);

        $subscription = $this->intelligence->globalSubscription($actor);
        $brief = $this->briefs->generate($subscription, (string) $data['cadence']);

        if (! $brief instanceof AssistantIntelligenceBrief) {
            return back()->with('status', __('assistant_intelligence.brief_disabled'));
        }

        $delivered = $this->dispatcher->deliver($brief);

        $this->audit->record(
            'assistant.intelligence.brief_generated',
            $actor,
            $brief,
            null,
            [
                'cadence' => $brief->cadence,
                'snapshot_at' => $brief->snapshot_at->toIso8601String(),
                'state' => $brief->fresh()->state,
                'delivered' => $delivered,
            ],
            $request,
        );

        return back()->with(
            'status',
            $delivered
                ? __('assistant_intelligence.brief_generated')
                : __('assistant_intelligence.brief_blocked'),
        );
    }

    public function acknowledge(Request $request, int $finding): RedirectResponse
    {
        return $this->transition(
            $request,
            $finding,
            AssistantIntelligenceFinding::STATUS_ACKNOWLEDGED,
            'assistant.intelligence.finding_acknowledged',
            'assistant_intelligence.finding_acknowledged',
        );
    }

    public function resolve(Request $request, int $finding): RedirectResponse
    {
        return $this->transition(
            $request,
            $finding,
            AssistantIntelligenceFinding::STATUS_RESOLVED,
            'assistant.intelligence.finding_resolved',
            'assistant_intelligence.finding_resolved',
        );
    }

    private function transition(
        Request $request,
        int $findingId,
        string $status,
        string $event,
        string $messageKey,
    ): RedirectResponse {
        $actor = $this->actor($request);
        $this->authorization->assertCanUse($actor);

        $before = AssistantIntelligenceFinding::query()
            ->whereKey($findingId)
            ->where('recipient_user_id', $actor->getKey())
            ->firstOrFail()
            ->toArray();

        $finding = $this->intelligence->transition($actor, $findingId, $status);

        $this->audit->record(
            $event,
            $actor,
            $finding,
            $before,
            $finding->toArray(),
            $request,
        );

        return back()->with('status', __($messageKey));
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
