<?php

namespace App\Http\Controllers\Admin;

use App\Domain\WhatsApp\DistributionRuleManager;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AdminNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class WhatsAppDistributionRulesController extends Controller
{
    public function __construct(
        private readonly DistributionRuleManager $rules,
        private readonly AdminNavigation $navigation,
    ) {}

    public function index(Request $request): View
    {
        $actor = $this->actor($request);
        $stores = $this->rules->permittedStores($actor);
        abort_unless($stores->isNotEmpty(), 403);

        $data = $request->validate([
            'store_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $storeId = (int) ($data['store_id'] ?? $stores->first()->id);
        $this->rules->assertStore($actor, $storeId);

        return view('admin.whatsapp-distribution-rules', [
            'user' => $actor,
            'navGroups' => $this->navigation->groupsFor($actor),
            'navContext' => 'whatsapp_supervisor',
            'stores' => $stores,
            'storeId' => $storeId,
            'agents' => $this->rules->assignableAgents($actor, $storeId),
            'rules' => $this->rules->paginate($actor, $storeId),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $payload = $this->validated($request);
        $this->rules->save($actor, $payload);

        return $this->back((int) $payload['store_id']);
    }

    public function update(Request $request, int $rule): RedirectResponse
    {
        $actor = $this->actor($request);
        $payload = $this->validated($request);
        $payload['revision'] = $request->validate([
            'revision' => ['required', 'string', 'size:64'],
        ])['revision'];
        $this->rules->save($actor, $payload, $rule);

        return $this->back((int) $payload['store_id']);
    }

    public function status(Request $request, int $rule): RedirectResponse
    {
        $actor = $this->actor($request);
        $payload = $request->validate([
            'store_id' => ['required', 'integer', 'min:1'],
            'is_active' => ['required', 'boolean'],
            'revision' => ['required', 'string', 'size:64'],
        ]);
        $this->rules->setActive(
            $actor, $rule, (int) $payload['store_id'], (bool) $payload['is_active'], (string) $payload['revision'],
        );

        return $this->back((int) $payload['store_id']);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'store_id' => ['required', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:120'],
            'priority' => ['required', 'integer', 'min:1', 'max:10000'],
            'strategy' => ['required', Rule::in(['round_robin', 'least_loaded'])],
            'is_active' => ['required', 'boolean'],
            'agent_ids' => ['sometimes', 'array', 'max:100'],
            'agent_ids.*' => ['integer', 'min:1', 'distinct'],
        ]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->is_active, 403);
        app()->setLocale(in_array($actor->locale, ['ar', 'en'], true) ? $actor->locale : 'ar');

        return $actor;
    }

    private function back(int $storeId): RedirectResponse
    {
        return redirect()->route('admin.whatsapp-supervisor.rules.index', ['store_id' => $storeId])
            ->with('status', __('whatsapp_supervisor.distribution.saved'));
    }
}
