<?php

namespace App\Http\Controllers\Admin;

use App\Domain\WhatsApp\ChatbotAnalyticsService;
use App\Domain\WhatsApp\ChatbotFeatureRegistry;
use App\Domain\WhatsApp\ChatbotIntentService;
use App\Domain\WhatsApp\ChatbotRuntimePolicy;
use App\Domain\WhatsApp\ChatbotSegmentationService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class WhatsAppChatbotController extends Controller
{
    private const SECTIONS = ['control', 'features', 'flows', 'ai', 'segments', 'analytics'];

    private const PREVIEW_SCREENS = ['home', 'new-order', 'reorder', 'products', 'cart', 'orders', 'finance', 'support'];

    public function __construct(
        private readonly ChatbotRuntimePolicy $runtime,
        private readonly ChatbotFeatureRegistry $features,
        private readonly ChatbotAnalyticsService $analytics,
        private readonly ChatbotSegmentationService $segmentation,
        private readonly ChatbotIntentService $intents,
    ) {}

    public function index(Request $request, ?string $section = null): View
    {
        $actor = $this->actor($request);
        $section = in_array($section, self::SECTIONS, true) ? $section : 'control';
        $this->authorizeSection($actor, $section);

        return view('admin.whatsapp-chatbot', [
            'section' => $section,
            'settings' => $this->runtime->settings(),
            'features' => $this->features->all(),
            'flows' => $this->flows(),
            'segments' => $this->segments(),
            'analytics' => $section === 'analytics'
                ? $this->analytics->dashboard((int) $request->integer('days', 30))
                : null,
            'days' => max(1, min(365, (int) $request->integer('days', 30))),
            'canManageRuntime' => $actor->hasPermission('chatbot.runtime.manage'),
            'canManageFeatures' => $actor->hasPermission('chatbot.features.manage'),
            'canManageFlows' => $actor->hasPermission('chatbot.flows.manage'),
            'canPublishFlows' => $actor->hasPermission('chatbot.flows.publish'),
            'canManageAi' => $actor->hasPermission('chatbot.ai.manage'),
            'canManageSegments' => $actor->hasPermission('chatbot.segments.manage'),
        ]);
    }

    public function updateRuntime(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $permission = (string) $request->input('scope') === 'ai'
            ? 'chatbot.ai.manage'
            : 'chatbot.runtime.manage';
        abort_unless($actor->hasPermission($permission), 403);

        $validated = $request->validate([
            'scope' => ['nullable', Rule::in(['runtime', 'ai'])],
            'revision' => ['required', 'integer', 'min:0'],
            'enabled' => ['sometimes', 'boolean'],
            'lifecycle' => ['sometimes', Rule::in(['DISABLED', 'INTERNAL_TEST', 'PILOT', 'ENABLED'])],
            'pilot_segment_codes' => ['nullable', 'array', 'max:50'],
            'pilot_segment_codes.*' => ['string', 'max:64', Rule::exists('whats_app_chatbot_segments', 'code')->where(fn ($query) => $query->where('enabled', true))],
            'emergency_stop' => ['sometimes', 'boolean'],
            'mode' => ['sometimes', Rule::in(['menu', 'ai', 'hybrid'])],
            'default_locale' => ['sometimes', Rule::in(['ar', 'en'])],
            'working_from' => ['nullable', 'date_format:H:i'],
            'working_to' => ['nullable', 'date_format:H:i'],
            'after_hours_handoff' => ['sometimes', 'boolean'],
            'welcome_ar' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'welcome_en' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'fallback_ar' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'fallback_en' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'ai_enabled' => ['sometimes', 'boolean'],
            'confidence_threshold' => ['sometimes', 'numeric', 'between:0,1'],
            'max_autonomous_steps' => ['sometimes', 'integer', 'between:1,10'],
            'require_order_confirmation' => ['sometimes', 'boolean'],
            'human_handoff_enabled' => ['sometimes', 'boolean'],
        ]);

        DB::transaction(function () use ($actor, $validated): void {
            $row = DB::table('whats_app_chatbot_settings')->lockForUpdate()->orderBy('id')->first();
            abort_unless($row !== null, 503);

            if ((int) $row->revision !== (int) $validated['revision']) {
                throw ValidationException::withMessages([
                    'revision' => [__('chatbot.admin.errors.stale_settings')],
                ]);
            }

            $updates = [];
            foreach ([
                'enabled', 'lifecycle', 'emergency_stop', 'mode', 'default_locale',
                'working_from', 'working_to', 'after_hours_handoff',
                'welcome_ar', 'welcome_en', 'fallback_ar', 'fallback_en',
                'ai_enabled', 'confidence_threshold', 'max_autonomous_steps',
                'require_order_confirmation', 'human_handoff_enabled',
            ] as $key) {
                if (array_key_exists($key, $validated)) {
                    $updates[$key] = $validated[$key];
                }
            }

            if (array_key_exists('pilot_segment_codes', $validated)) {
                $pilotSegmentCodes = array_values(array_unique(array_filter(
                    $validated['pilot_segment_codes'] ?? [],
                    static fn (mixed $value): bool => is_string($value) && trim($value) !== '',
                )));
                $updates['pilot_segment_codes'] = $pilotSegmentCodes === []
                    ? null
                    : json_encode($pilotSegmentCodes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            if (($updates['lifecycle'] ?? $row->lifecycle) === 'PILOT') {
                $pilotCodes = array_key_exists('pilot_segment_codes', $updates)
                    ? (json_decode((string) ($updates['pilot_segment_codes'] ?? '[]'), true) ?: [])
                    : (json_decode((string) ($row->pilot_segment_codes ?? '[]'), true) ?: []);

                if ($pilotCodes === []) {
                    throw ValidationException::withMessages([
                        'pilot_segment_codes' => [__('chatbot.admin.errors.pilot_segment_required')],
                    ]);
                }
            }

            $updates['updated_by'] = $actor->getKey();
            $updates['revision'] = ((int) $row->revision) + 1;
            $updates['updated_at'] = now();

            DB::table('whats_app_chatbot_settings')->where('id', $row->id)->update($updates);

            $this->analytics->record(
                'admin_runtime_updated',
                null,
                null,
                null,
                null,
                null,
                ['changed' => array_keys(array_diff_key($updates, ['updated_at' => true]))],
                (int) $actor->getKey(),
            );
        }, 3);

        return back()->with('status', __('chatbot.admin.saved'));
    }

    public function updateFeature(Request $request, int $feature): RedirectResponse
    {
        $actor = $this->actor($request);
        abort_unless($actor->hasPermission('chatbot.features.manage'), 403);

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'visible' => ['required', 'boolean'],
            'ai_allowed' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'between:1,9999'],
            'segment_codes' => ['nullable', 'array', 'max:50'],
            'segment_codes.*' => ['string', 'max:64'],
        ]);

        $row = DB::table('whats_app_chatbot_features')->where('id', $feature)->first();
        abort_unless($row !== null, 404);

        $segmentCodes = array_values(array_unique(array_filter(
            $validated['segment_codes'] ?? [],
            static fn (mixed $value): bool => is_string($value) && trim($value) !== '',
        )));

        DB::table('whats_app_chatbot_features')->where('id', $feature)->update([
            'enabled' => (bool) $validated['enabled'],
            'visible' => (bool) $validated['visible'],
            'ai_allowed' => (bool) $validated['ai_allowed'],
            'sort_order' => (int) $validated['sort_order'],
            'segment_codes' => $segmentCodes === [] ? null : json_encode($segmentCodes, JSON_UNESCAPED_UNICODE),
            'updated_by' => $actor->getKey(),
            'updated_at' => now(),
        ]);

        $this->analytics->record(
            'admin_feature_updated',
            null,
            null,
            (string) $row->code,
            null,
            null,
            ['enabled' => (bool) $validated['enabled'], 'visible' => (bool) $validated['visible']],
            (int) $actor->getKey(),
        );

        return back()->with('status', __('chatbot.admin.saved'));
    }

    public function storeFlow(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        abort_unless($actor->hasPermission('chatbot.flows.manage'), 403);

        $validated = $this->validateFlow($request);
        $code = Str::slug((string) $validated['code'], '_');
        if ($code === '') {
            throw ValidationException::withMessages(['code' => [__('chatbot.admin.errors.invalid_code')]]);
        }

        $definition = $this->flowDefinition($validated);

        DB::transaction(function () use ($actor, $validated, $code, $definition): void {
            $flowId = (int) DB::table('whats_app_chatbot_flows')->insertGetId([
                'code' => $code,
                'name_ar' => $validated['name_ar'],
                'name_en' => $validated['name_en'],
                'status' => 'draft',
                'version' => 1,
                'definition' => json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_by' => $actor->getKey(),
                'published_by' => null,
                'published_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->writeFlowVersion($flowId, 1, 'draft', $definition, (int) $actor->getKey());
            $this->analytics->record('admin_flow_created', null, null, null, null, null, ['flow_id' => $flowId], (int) $actor->getKey());
        }, 3);

        return back()->with('status', __('chatbot.admin.saved'));
    }

    public function updateFlow(Request $request, int $flow): RedirectResponse
    {
        $actor = $this->actor($request);
        abort_unless($actor->hasPermission('chatbot.flows.manage'), 403);

        $validated = $this->validateFlow($request, false);
        $definition = $this->flowDefinition($validated);

        DB::transaction(function () use ($actor, $flow, $validated, $definition): void {
            $row = DB::table('whats_app_chatbot_flows')->where('id', $flow)->lockForUpdate()->first();
            abort_unless($row !== null, 404);

            $version = ((int) $row->version) + 1;
            DB::table('whats_app_chatbot_flows')->where('id', $flow)->update([
                'name_ar' => $validated['name_ar'],
                'name_en' => $validated['name_en'],
                'status' => 'draft',
                'version' => $version,
                'definition' => json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'published_by' => null,
                'published_at' => null,
                'updated_at' => now(),
            ]);
            $this->writeFlowVersion($flow, $version, 'draft', $definition, (int) $actor->getKey());
            $this->analytics->record('admin_flow_updated', null, null, null, null, null, ['flow_id' => $flow, 'version' => $version], (int) $actor->getKey());
        }, 3);

        return back()->with('status', __('chatbot.admin.saved'));
    }

    public function publishFlow(Request $request, int $flow): RedirectResponse
    {
        $actor = $this->actor($request);
        abort_unless($actor->hasPermission('chatbot.flows.publish'), 403);

        DB::transaction(function () use ($actor, $flow): void {
            $row = DB::table('whats_app_chatbot_flows')->where('id', $flow)->lockForUpdate()->first();
            abort_unless($row !== null, 404);

            DB::table('whats_app_chatbot_flows')->where('status', 'published')->where('id', '<>', $flow)->update([
                'status' => 'archived',
                'updated_at' => now(),
            ]);
            DB::table('whats_app_chatbot_flows')->where('id', $flow)->update([
                'status' => 'published',
                'published_by' => $actor->getKey(),
                'published_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('whats_app_chatbot_flow_versions')
                ->where('flow_id', $flow)
                ->where('version', (int) $row->version)
                ->update(['status' => 'published', 'updated_at' => now()]);

            $this->analytics->record('admin_flow_published', null, null, null, null, null, ['flow_id' => $flow, 'version' => (int) $row->version], (int) $actor->getKey());
        }, 3);

        return back()->with('status', __('chatbot.admin.flow_published'));
    }

    public function rollbackFlow(Request $request, int $flow): RedirectResponse
    {
        $actor = $this->actor($request);
        abort_unless($actor->hasPermission('chatbot.flows.publish'), 403);

        DB::transaction(function () use ($actor, $flow): void {
            $row = DB::table('whats_app_chatbot_flows')->where('id', $flow)->lockForUpdate()->first();
            abort_unless($row !== null, 404);

            $previous = DB::table('whats_app_chatbot_flow_versions')
                ->where('flow_id', $flow)
                ->where('version', '<', (int) $row->version)
                ->orderByDesc('version')
                ->first();

            if ($previous === null) {
                throw ValidationException::withMessages([
                    'flow' => [__('chatbot.admin.errors.no_rollback')],
                ]);
            }

            $nextVersion = ((int) $row->version) + 1;
            $definition = json_decode((string) $previous->definition, true);
            $definition = is_array($definition) ? $definition : [];

            DB::table('whats_app_chatbot_flows')->where('id', $flow)->update([
                'status' => 'draft',
                'version' => $nextVersion,
                'definition' => json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'published_by' => null,
                'published_at' => null,
                'updated_at' => now(),
            ]);
            $this->writeFlowVersion($flow, $nextVersion, 'draft', $definition, (int) $actor->getKey());
            $this->analytics->record('admin_flow_rolled_back', null, null, null, null, null, ['flow_id' => $flow, 'source_version' => (int) $previous->version, 'version' => $nextVersion], (int) $actor->getKey());
        }, 3);

        return back()->with('status', __('chatbot.admin.flow_rolled_back'));
    }

    public function storeSegment(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        abort_unless($actor->hasPermission('chatbot.segments.manage'), 403);

        $validated = $this->validateSegment($request);
        $code = Str::slug((string) $validated['code'], '_');
        if ($code === '') {
            throw ValidationException::withMessages(['code' => [__('chatbot.admin.errors.invalid_code')]]);
        }

        DB::table('whats_app_chatbot_segments')->insert([
            'code' => $code,
            'name_ar' => $validated['name_ar'],
            'name_en' => $validated['name_en'],
            'enabled' => (bool) $validated['enabled'],
            'priority' => (int) $validated['priority'],
            'rules' => json_encode($this->segmentRules($validated), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_by' => $actor->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->analytics->record('admin_segment_created', null, null, null, null, null, ['code' => $code], (int) $actor->getKey());

        return back()->with('status', __('chatbot.admin.saved'));
    }

    public function updateSegment(Request $request, int $segment): RedirectResponse
    {
        $actor = $this->actor($request);
        abort_unless($actor->hasPermission('chatbot.segments.manage'), 403);

        $validated = $this->validateSegment($request, false);
        $row = DB::table('whats_app_chatbot_segments')->where('id', $segment)->first();
        abort_unless($row !== null, 404);

        DB::table('whats_app_chatbot_segments')->where('id', $segment)->update([
            'name_ar' => $validated['name_ar'],
            'name_en' => $validated['name_en'],
            'enabled' => (bool) $validated['enabled'],
            'priority' => (int) $validated['priority'],
            'rules' => json_encode($this->segmentRules($validated), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_by' => $actor->getKey(),
            'updated_at' => now(),
        ]);

        $this->analytics->record('admin_segment_updated', null, null, null, null, null, ['code' => (string) $row->code], (int) $actor->getKey());

        return back()->with('status', __('chatbot.admin.saved'));
    }

    public function testAi(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        abort_unless($actor->hasPermission('chatbot.ai.view'), 403);

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
        ]);

        $action = $this->intents->interpret((string) $validated['message']);

        return back()->with('chatbot_ai_test', [
            'message' => (string) $validated['message'],
            'action' => $action->toArray(),
        ]);
    }

    public function preview(Request $request, string $screen): View
    {
        $actor = $this->actor($request);
        abort_unless($actor->hasPermission('chatbot.view'), 403);
        abort_unless(in_array($screen, self::PREVIEW_SCREENS, true), 404);
        if ($screen === 'finance') {
            abort_unless($actor->hasPermission('finance.view'), 403);
        }

        $customers = DB::table('platform_customers')
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(100)
            ->get(['id', 'name', 'phone', 'email', 'legacy_customer_id']);

        $selectedCustomerId = $request->integer('customer_id');
        $customer = $selectedCustomerId > 0
            ? $customers->firstWhere('id', $selectedCustomerId)
            : $customers->first();

        return view('admin.whatsapp-chatbot-preview', [
            'screen' => $screen,
            'customers' => $customers,
            'customer' => $customer,
            'features' => $this->features->visibleEnabled(
                $this->segmentation->codesFor($customer === null ? null : (int) $customer->id),
            ),
            'products' => $this->previewProducts(),
            'orders' => $customer === null ? collect() : $this->previewOrders((int) $customer->legacy_customer_id),
            'invoices' => $customer === null ? collect() : $this->previewInvoices((int) $customer->id),
            'cartItems' => $customer === null ? collect() : $this->previewCart((int) $customer->legacy_customer_id),
            'settings' => $this->runtime->settings(),
        ]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->is_active, 401);

        return $actor;
    }

    private function authorizeSection(User $actor, string $section): void
    {
        $permission = match ($section) {
            'features' => 'chatbot.features.view',
            'flows' => 'chatbot.flows.view',
            'ai' => 'chatbot.ai.view',
            'segments' => 'chatbot.segments.view',
            'analytics' => 'chatbot.analytics.view',
            default => 'chatbot.view',
        };

        abort_unless($actor->hasPermission($permission), 403);
    }

    private function flows(): Collection
    {
        return Schema::hasTable('whats_app_chatbot_flows')
            ? DB::table('whats_app_chatbot_flows')->orderByDesc('updated_at')->get()
            : collect();
    }

    private function segments(): Collection
    {
        return Schema::hasTable('whats_app_chatbot_segments')
            ? DB::table('whats_app_chatbot_segments')->orderBy('priority')->orderBy('id')->get()
            : collect();
    }

    /** @return array<string, mixed> */
    private function validateFlow(Request $request, bool $withCode = true): array
    {
        $rules = [
            'name_ar' => ['required', 'string', 'max:191'],
            'name_en' => ['required', 'string', 'max:191'],
            'message_ar' => ['required', 'string', 'max:1000'],
            'message_en' => ['required', 'string', 'max:1000'],
            'primary_action' => ['required', Rule::in(['new_order', 'repeat_order', 'product_search', 'order_tracking', 'complaints', 'human_handoff'])],
            'menu_actions' => ['nullable', 'array', 'max:6'],
            'menu_actions.*' => ['string', 'distinct', Rule::in(['new_order', 'repeat_order', 'product_search', 'order_tracking', 'complaints', 'human_handoff'])],
        ];
        if ($withCode) {
            $rules['code'] = ['required', 'string', 'max:64', 'unique:whats_app_chatbot_flows,code'];
        }

        return $request->validate($rules);
    }

    /** @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    private function flowDefinition(array $validated): array
    {
        $allowedActions = ['new_order', 'repeat_order', 'product_search', 'order_tracking', 'complaints', 'human_handoff'];
        $menuActions = array_values(array_unique(array_filter(
            $validated['menu_actions'] ?? [],
            static fn (mixed $action): bool => is_string($action) && in_array($action, $allowedActions, true),
        )));

        if ($menuActions === []) {
            $menuActions = [(string) $validated['primary_action']];
        } elseif (! in_array((string) $validated['primary_action'], $menuActions, true)) {
            array_unshift($menuActions, (string) $validated['primary_action']);
        }

        $actionNodes = [];
        foreach ($menuActions as $action) {
            $actionNodes[] = [
                'id' => 'action_'.$action,
                'type' => 'action',
                'action' => $action,
                'requires_confirmation' => in_array($action, ['new_order', 'repeat_order'], true),
            ];
        }

        return [
            'schema' => 2,
            'start' => 'welcome',
            'nodes' => array_merge([
                [
                    'id' => 'welcome',
                    'type' => 'message',
                    'content' => ['ar' => $validated['message_ar'], 'en' => $validated['message_en']],
                    'next' => 'menu',
                ],
                [
                    'id' => 'menu',
                    'type' => 'menu',
                    'options' => $menuActions,
                    'next' => 'action_'.$validated['primary_action'],
                ],
            ], $actionNodes),
        ];
    }

    /** @param array<string, mixed> $definition */
    private function writeFlowVersion(int $flowId, int $version, string $status, array $definition, int $actorId): void
    {
        DB::table('whats_app_chatbot_flow_versions')->insert([
            'flow_id' => $flowId,
            'version' => $version,
            'status' => $status,
            'definition' => json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function validateSegment(Request $request, bool $withCode = true): array
    {
        $rules = [
            'name_ar' => ['required', 'string', 'max:191'],
            'name_en' => ['required', 'string', 'max:191'],
            'enabled' => ['required', 'boolean'],
            'priority' => ['required', 'integer', 'between:1,9999'],
            'customer_type' => ['nullable', Rule::in(['any', 'b2b', 'b2c'])],
            'min_orders' => ['nullable', 'integer', 'between:0,100000'],
            'dormant_days' => ['nullable', 'integer', 'between:0,3650'],
        ];
        if ($withCode) {
            $rules['code'] = ['required', 'string', 'max:64', 'unique:whats_app_chatbot_segments,code'];
        }

        return $request->validate($rules);
    }

    /** @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    private function segmentRules(array $validated): array
    {
        return [
            'customer_type' => $validated['customer_type'] ?? 'any',
            'min_orders' => (int) ($validated['min_orders'] ?? 0),
            'dormant_days' => (int) ($validated['dormant_days'] ?? 0),
        ];
    }

    private function previewProducts(): Collection
    {
        return DB::table('products')
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(12)
            ->get(['id', 'name', 'sku', 'description']);
    }

    private function previewOrders(int $legacyCustomerId): Collection
    {
        return DB::table('orders')
            ->where('customer_id', $legacyCustomerId)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();
    }

    private function previewInvoices(int $platformCustomerId): Collection
    {
        return DB::table('invoices')
            ->where('platform_customer_id', $platformCustomerId)
            ->orderByDesc('issued_at')
            ->limit(10)
            ->get();
    }

    private function previewCart(int $legacyCustomerId): Collection
    {
        $cartId = DB::table('carts')
            ->where('customer_id', $legacyCustomerId)
            ->orderByDesc('updated_at')
            ->value('id');

        if ($cartId === null) {
            return collect();
        }

        return DB::table('cart_items')
            ->join('products', 'products.id', '=', 'cart_items.product_id')
            ->where('cart_items.cart_id', $cartId)
            ->select('cart_items.*', 'products.name', 'products.sku')
            ->orderBy('cart_items.id')
            ->get();
    }
}
