<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Assistant\Contracts\AgentOrchestratorInterface;
use App\Domain\Assistant\Data\AgentExecutionBudget;
use App\Domain\Assistant\Data\AgentTurnRequest;
use App\Domain\Assistant\Data\AssistantToolRequest;
use App\Domain\Assistant\Data\AssistantToolResult;
use App\Domain\Assistant\Models\AssistantConversation;
use App\Domain\Assistant\Models\AssistantConversationState;
use App\Domain\Assistant\Models\AssistantMessage;
use App\Domain\Assistant\Security\AiPrincipalContextResolver;
use App\Domain\Assistant\Support\AssistantPageContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AssistantObservability;
use App\Services\AssistantRuntimeSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class AssistantController extends Controller
{
    private const ACTION_ROUTES = [
        'admin.b2b.module',
        'admin.b2c.module',
        'admin.catalog.index',
        'admin.operations.orders.index',
        'admin.reports.index',
        'admin.retail-stores.index',
    ];

    public function __construct(
        private readonly AgentOrchestratorInterface $orchestrator,
        private readonly AssistantPageContext $pageContext,
        private readonly AiPrincipalContextResolver $principalContext,
        private readonly AssistantRuntimeSettings $runtimeSettings,
        private readonly AssistantObservability $observability,
    ) {}

    public function bootstrap(Request $request): JsonResponse
    {
        $user = $this->assistantUser($request);
        $conversation = AssistantConversation::query()
            ->where('user_id', $user->getKey())
            ->latest('updated_at')
            ->first();

        $locale = $conversation === null ? $user->locale : $conversation->locale;

        return response()->json(['data' => [
            'conversation' => $conversation === null ? null : $this->conversationPayload($conversation),
            'messages' => $conversation === null ? [] : $this->messagePayloads($conversation),
            'suggested_prompts' => $this->suggestedPrompts($this->locale($locale)),
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $this->assistantUser($request);

        return response()->json(['data' => [
            'conversations' => AssistantConversation::query()
                ->where('user_id', $user->getKey())
                ->latest('updated_at')
                ->limit(20)
                ->get()
                ->map(fn (AssistantConversation $conversation): array => $this->conversationPayload($conversation))
                ->values()
                ->all(),
        ]]);
    }

    public function storeConversation(Request $request): JsonResponse
    {
        $user = $this->assistantUser($request);
        $data = $request->validate([
            'locale' => ['nullable', Rule::in(['ar', 'en'])],
            'context' => ['nullable', 'array'],
        ]);
        $locale = $this->locale($data['locale'] ?? $user->locale);
        $context = $this->pageContext->resolve($user, (array) ($data['context'] ?? []));

        $conversation = DB::transaction(function () use ($user, $locale, $context): AssistantConversation {
            $conversation = AssistantConversation::query()->create([
                'public_id' => (string) Str::uuid(),
                'user_id' => $user->getKey(),
                'locale' => $locale,
            ]);

            if ($context['authorized_entities'] !== []) {
                AssistantConversationState::query()->create([
                    'conversation_id' => $conversation->getKey(),
                    'last_authorized_entities' => $context['authorized_entities'],
                    'locale' => $locale,
                ]);
            }

            return $conversation;
        });

        return response()->json(['data' => [
            'conversation' => $this->conversationPayload($conversation),
            'messages' => [],
            'suggested_prompts' => $this->suggestedPrompts($locale),
        ]], 201);
    }

    public function messages(Request $request, string $conversationId): JsonResponse
    {
        $user = $this->assistantUser($request);
        $conversation = $this->conversation($user, $conversationId);

        return response()->json(['data' => [
            'conversation' => $this->conversationPayload($conversation),
            'messages' => $this->messagePayloads($conversation),
            'suggested_prompts' => $this->suggestedPrompts($conversation->locale),
        ]]);
    }

    public function storeMessage(Request $request, string $conversationId): JsonResponse
    {
        $user = $this->assistantUser($request);
        $conversation = $this->conversation($user, $conversationId);
        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'locale' => ['nullable', Rule::in(['ar', 'en'])],
            'context' => ['nullable', 'array'],
        ]);
        $message = trim((string) $data['message']);
        $locale = $this->messageLocale(
            $message,
            $this->locale($data['locale'] ?? $conversation->locale ?? $user->locale),
        );
        $context = $this->pageContext->resolve($user, (array) ($data['context'] ?? []));
        $correlationId = (string) Str::uuid();
        $turnStartedAt = hrtime(true);
        $traceStoreId = $this->positiveInt(
            $context['store_id']
                ?? data_get($context, 'authorized_entities.store_id'),
        );

        $this->observability->record($correlationId, 'turn.started', [
            'actor_user_id' => $user->getKey(),
            'store_id' => $traceStoreId,
            'phase' => 'turn',
            'outcome' => 'started',
        ]);

        try {
            $principal = $this->principalContext->resolve($user, $locale, $context, $correlationId);

            $payload = DB::transaction(function () use ($user, $conversation, $message, $locale, $context, $correlationId, $principal): array {
                $locked = AssistantConversation::query()
                    ->whereKey($conversation->getKey())
                    ->where('user_id', $user->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $state = AssistantConversationState::query()
                    ->where('conversation_id', $locked->getKey())
                    ->first();

                AssistantMessage::query()->create([
                    'conversation_id' => $locked->getKey(),
                    'role' => AssistantMessage::ROLE_USER,
                    'content' => $message,
                    'correlation_id' => $correlationId,
                ]);

                $agentResult = $this->orchestrator->run(new AgentTurnRequest(
                    actorUserId: (int) $user->getKey(),
                    userMessage: $message,
                    locale: $locale,
                    principal: $principal,
                    correlationId: $correlationId,
                    context: $context,
                    state: $this->brainState($state),
                    conversationPublicId: (string) $locked->public_id,
                    budget: AgentExecutionBudget::fromConfig(),
                ));

                $brainResult = $agentResult->brainResult;
                $toolResult = $agentResult->toolResult;
                $toolRequest = $agentResult->toolRequest;

                $cards = $toolResult === null ? $brainResult->cards : $this->cards($brainResult->intent, $locale, $toolResult);
                $actions = $toolResult === null ? $this->actions($brainResult->actions) : $this->actions($toolResult->actions);
                $suggested = $brainResult->suggestedPrompts === [] ? $this->suggestedPrompts($locale) : $brainResult->suggestedPrompts;
                $content = $agentResult->message;
                $assistantPayload = [
                    'cards' => $cards,
                    'actions' => $actions,
                    'suggested_prompts' => $suggested,
                    'data' => $toolResult === null ? [] : $toolResult->data,
                    'agent' => $agentResult->metadata(),
                ];

                $assistantMessage = AssistantMessage::query()->create([
                    'conversation_id' => $locked->getKey(),
                    'role' => AssistantMessage::ROLE_ASSISTANT,
                    'content' => $content,
                    'intent' => $brainResult->intent,
                    'confidence' => $brainResult->confidence,
                    'payload' => $assistantPayload,
                    'correlation_id' => $correlationId,
                ]);

                $this->persistState($locked, $state, $brainResult->state, $toolResult, $toolRequest, $context, $locale);

                $locked->forceFill([
                    'locale' => $locale,
                    'title' => $locked->title ?: Str::limit($message, 120, ''),
                    'last_message_at' => now(),
                ])->save();

                return [
                    'conversation' => $this->conversationPayload($locked->fresh()),
                    'assistant_message' => $this->messagePayload($assistantMessage),
                    'suggested_prompts' => $suggested,
                    '_trace_outcome' => $agentResult->status,
                ];
            });

            $this->observability->record($correlationId, 'turn.completed', [
                'actor_user_id' => $user->getKey(),
                'store_id' => $principal->activeStoreId ?? $traceStoreId,
                'phase' => 'turn',
                'outcome' => (string) ($payload['_trace_outcome'] ?? 'COMPLETE'),
                'latency_ms' => (int) round((hrtime(true) - $turnStartedAt) / 1_000_000),
            ]);
            unset($payload['_trace_outcome']);
        } catch (HttpExceptionInterface|ValidationException $exception) {
            $status = $exception instanceof HttpExceptionInterface
                ? 'HTTP_'.$exception->getStatusCode()
                : 'VALIDATION_FAILED';

            $this->observability->record($correlationId, 'turn.failed', [
                'actor_user_id' => $user->getKey(),
                'store_id' => $traceStoreId,
                'phase' => 'turn',
                'outcome' => $status,
                'status_code' => $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 422,
                'latency_ms' => (int) round((hrtime(true) - $turnStartedAt) / 1_000_000),
            ]);

            throw $exception;
        } catch (InvalidArgumentException $exception) {
            $this->observability->record($correlationId, 'turn.failed', [
                'actor_user_id' => $user->getKey(),
                'store_id' => $traceStoreId,
                'phase' => 'turn',
                'outcome' => 'REQUEST_INVALID',
                'status_code' => 422,
                'latency_ms' => (int) round((hrtime(true) - $turnStartedAt) / 1_000_000),
            ]);

            return response()->json(['error' => [
                'code' => 'ASSISTANT_REQUEST_INVALID',
                'message' => $locale === 'ar' ? 'تعذر تنفيذ هذا الطلب بالمدخلات الحالية.' : 'This request cannot be completed with the current inputs.',
            ]], 422);
        } catch (Throwable $exception) {
            $this->observability->record($correlationId, 'turn.failed', [
                'actor_user_id' => $user->getKey(),
                'store_id' => $traceStoreId,
                'phase' => 'turn',
                'outcome' => 'UNAVAILABLE',
                'status_code' => 503,
                'latency_ms' => (int) round((hrtime(true) - $turnStartedAt) / 1_000_000),
            ]);
            report($exception);

            return response()->json(['error' => [
                'code' => 'ASSISTANT_UNAVAILABLE',
                'message' => $locale === 'ar' ? 'تعذر تجهيز رد المساعد الآن.' : 'The Assistant could not prepare a response right now.',
            ]], 503);
        }

        return response()->json(['data' => $payload]);
    }

    public function clear(Request $request, string $conversationId): JsonResponse
    {
        $user = $this->assistantUser($request);
        $conversation = $this->conversation($user, $conversationId);

        DB::transaction(function () use ($conversation): void {
            $conversation->messages()->delete();
            $conversation->state()->delete();
            $conversation->forceFill(['title' => null, 'last_message_at' => null])->save();
        });

        return response()->json(['data' => [
            'conversation' => $this->conversationPayload($conversation->fresh()),
            'messages' => [],
            'suggested_prompts' => $this->suggestedPrompts($conversation->locale),
        ]]);
    }

    public function destroy(Request $request, string $conversationId): Response
    {
        $user = $this->assistantUser($request);
        $conversation = $this->conversation($user, $conversationId);
        $conversation->delete();

        return response()->noContent();
    }

    private function assistantUser(Request $request): User
    {
        abort_unless($this->runtimeSettings->enabled(), 404);
        abort_unless($this->runtimeSettings->readOnly(), 503);

        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($this->canUse($user), 403);

        return $user;
    }

    private function canUse(User $user): bool
    {
        if ($user->hasPermission('assistant.use')) {
            return true;
        }

        return $user->storeRoleAssignments()
            ->whereHas('role', fn ($roleQuery) => $roleQuery
                ->where('roles.is_active', true)
                ->whereHas('permissions', fn ($permissionQuery) => $permissionQuery->where('permissions.code', 'assistant.use')))
            ->exists();
    }

    private function conversation(User $user, string $publicId): AssistantConversation
    {
        return AssistantConversation::query()
            ->where('public_id', $publicId)
            ->where('user_id', $user->getKey())
            ->firstOrFail();
    }

    /** @return array<string,mixed> */
    private function brainState(?AssistantConversationState $state): array
    {
        if ($state === null) {
            return [];
        }

        return [
            'last_intent' => $state->last_intent,
            'last_period' => $state->last_period,
            'last_authorized_entities' => $state->last_authorized_entities,
            'last_tool' => $state->last_tool,
            'result_references' => $state->result_references,
            'pending_clarification_slots' => $state->pending_clarification_slots,
            'locale' => $state->locale,
        ];
    }

    private function persistState(
        AssistantConversation $conversation,
        ?AssistantConversationState $state,
        array $brainState,
        ?AssistantToolResult $toolResult,
        ?AssistantToolRequest $toolRequest,
        array $context,
        string $locale,
    ): void {
        $preserveContext = (bool) ($brainState['preserve_context'] ?? false);
        $authorized = is_array($context['authorized_entities'] ?? null) ? $context['authorized_entities'] : [];
        $previousAuthorized = $state === null ? [] : (array) $state->last_authorized_entities;

        if ($preserveContext && $authorized === []) {
            $authorized = $previousAuthorized;
        }

        if ($toolRequest?->storeId !== null) {
            $authorized['store_id'] = $toolRequest->storeId;
        }
        if ($toolRequest?->channel !== null) {
            $authorized['channel'] = $toolRequest->channel;
        }

        $references = $toolResult === null
            ? ($preserveContext && $state !== null ? (array) $state->result_references : [])
            : $toolResult->references;

        foreach ($references as $reference) {
            $type = is_string($reference['type'] ?? null) ? $reference['type'] : null;
            $id = $this->positiveInt($reference['id'] ?? null);
            if ($type !== null && $id !== null && in_array($type, ['order', 'customer', 'driver', 'product', 'store'], true)) {
                $authorized[$type.'_id'] = $id;
            }
            if (($storeId = $this->positiveInt($reference['store_id'] ?? null)) !== null) {
                $authorized['store_id'] = $storeId;
            }
            if (($referenceChannel = $this->channel($reference['channel'] ?? null)) !== null) {
                $authorized['channel'] = $referenceChannel;
            }
        }

        ($state ?? new AssistantConversationState(['conversation_id' => $conversation->getKey()]))
            ->forceFill([
                'conversation_id' => $conversation->getKey(),
                'last_intent' => $preserveContext ? $state?->last_intent : ($brainState['last_intent'] ?? null),
                'last_period' => $preserveContext
                    ? ($state?->last_period === null ? null : (array) $state->last_period)
                    : (is_array($brainState['last_period'] ?? null) ? $brainState['last_period'] : null),
                'last_authorized_entities' => $authorized,
                'last_tool' => $preserveContext
                    ? $state?->last_tool
                    : ($toolResult === null ? null : ($brainState['last_tool'] ?? $brainState['last_intent'] ?? null)),
                'result_references' => $references,
                'pending_clarification_slots' => (bool) ($brainState['pending_clarification'] ?? false)
                    ? [['intent' => $brainState['last_intent'] ?? null]]
                    : [],
                'locale' => $locale,
            ])
            ->save();
    }

    /** @return list<array{label:string,url:string}> */
    private function actions(array $actions): array
    {
        $result = [];
        foreach ($actions as $action) {
            if (! in_array($action->routeName, self::ACTION_ROUTES, true)) {
                continue;
            }

            try {
                $url = route($action->routeName, $action->routeParameters, false);
            } catch (Throwable) {
                continue;
            }

            if (str_starts_with($url, '/admin')) {
                $result[] = ['label' => $action->label, 'url' => $url];
            }
        }

        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function cards(?string $intent, string $locale, AssistantToolResult $result): array
    {
        if ($result->cards !== []) {
            return array_slice($result->cards, 0, 8);
        }

        $scalars = [];
        foreach ($result->data as $key => $value) {
            if (is_scalar($value) && count($scalars) < 8) {
                $scalars[(string) $key] = $value;
            }
        }

        return $scalars === [] ? [] : [[
            'title' => $this->intentLabel($intent, $locale),
            'data' => $scalars,
            'labels' => $this->cardLabels(array_keys($scalars), $locale),
        ]];
    }

    private function intentLabel(?string $intent, string $locale): string
    {
        $labels = [
            'sales.summary' => ['en' => 'Sales summary', 'ar' => 'ملخص المبيعات'],
            'sales.compare' => ['en' => 'Sales comparison', 'ar' => 'مقارنة المبيعات'],
            'orders.summary' => ['en' => 'Orders summary', 'ar' => 'ملخص الطلبات'],
            'orders.lookup' => ['en' => 'Order details', 'ar' => 'تفاصيل الطلب'],
            'stores.summary' => ['en' => 'Stores summary', 'ar' => 'ملخص المتاجر'],
            'stores.compare' => ['en' => 'Store comparison', 'ar' => 'مقارنة المتاجر'],
            'customers.summary' => ['en' => 'Customers summary', 'ar' => 'ملخص العملاء'],
            'customers.activity' => ['en' => 'Customer activity', 'ar' => 'نشاط العميل'],
            'products.performance' => ['en' => 'Product performance', 'ar' => 'أداء المنتجات'],
            'orders.late' => ['en' => 'Late orders', 'ar' => 'الطلبات المتأخرة'],
            'orders.cancelled' => ['en' => 'Cancelled orders', 'ar' => 'الطلبات الملغية'],
            'cancellations.summary' => ['en' => 'Cancellations summary', 'ar' => 'ملخص الإلغاءات'],
            'drivers.status' => ['en' => 'Driver status', 'ar' => 'حالة السائقين'],
            'drivers.assignments' => ['en' => 'Driver assignments', 'ar' => 'تكليفات السائقين'],
            'inventory.alerts' => ['en' => 'Inventory alerts', 'ar' => 'تنبيهات المخزون'],
            'brief.daily' => ['en' => 'Daily brief', 'ar' => 'ملخص اليوم'],
            'agent.multi_tool' => ['en' => 'Grounded analysis', 'ar' => 'تحليل موثق'],
        ];

        return $labels[$intent][$locale] ?? ($intent ?? ($locale === 'ar' ? 'النتيجة' : 'Result'));
    }

    /** @return array<string,mixed> */
    private function conversationPayload(AssistantConversation $conversation): array
    {
        return [
            'id' => (int) $conversation->getKey(),
            'public_id' => (string) $conversation->public_id,
            'locale' => (string) $conversation->locale,
            'title' => $conversation->title,
            'last_message_at' => $conversation->last_message_at === null
                ? null
                : CarbonImmutable::parse((string) $conversation->last_message_at)->toIso8601String(),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function messagePayloads(AssistantConversation $conversation): array
    {
        return AssistantMessage::query()
            ->where('conversation_id', $conversation->getKey())
            ->latest('id')
            ->limit(50)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (AssistantMessage $message): array => $this->messagePayload($message))
            ->all();
    }

    /** @return array<string,mixed> */
    private function messagePayload(AssistantMessage $message): array
    {
        /** @var array<string, mixed> $payload */
        $payload = $message->getAttribute('payload') ?? [];

        return [
            'id' => (int) $message->getKey(),
            'role' => (string) $message->role,
            'content' => (string) $message->content,
            'intent' => $message->intent,
            'confidence' => $message->confidence === null ? null : (float) $message->confidence,
            'created_at' => $message->created_at?->toIso8601String(),
            'cards' => is_array($payload['cards'] ?? null) ? $payload['cards'] : [],
            'actions' => is_array($payload['actions'] ?? null) ? $payload['actions'] : [],
            'suggested_prompts' => is_array($payload['suggested_prompts'] ?? null) ? $payload['suggested_prompts'] : [],
            'data' => is_array($payload['data'] ?? null) ? $payload['data'] : [],
        ];
    }

    /** @return list<string> */
    private function suggestedPrompts(string $locale): array
    {
        return $locale === 'ar'
            ? ['مبيعات اليوم', 'الطلبات المتأخرة', 'ملخص اليوم']
            : ['Sales today', 'Late orders', 'Daily brief'];
    }

    private function locale(mixed $value): string
    {
        return is_string($value) && strtolower($value) === 'en' ? 'en' : 'ar';
    }

    private function messageLocale(string $message, string $fallback): string
    {
        // Arabic script wins for mixed operational phrases such as "مبيعات B2B اليوم".
        if (preg_match('/\\p{Arabic}/u', $message) === 1) {
            return 'ar';
        }

        if (preg_match('/[A-Za-z]/', $message) === 1) {
            return 'en';
        }

        return $fallback;
    }

    /**
     * @param  list<string|int>  $keys
     * @return array<string, string>
     */
    private function cardLabels(array $keys, string $locale): array
    {
        $labels = [];

        foreach ($keys as $key) {
            $key = (string) $key;
            $labels[$key] = $this->metricLabel($key, $locale);
        }

        return $labels;
    }

    private function metricLabel(string $key, string $locale): string
    {
        $arabic = [
            'from' => 'من',
            'to' => 'إلى',
            'as_of' => 'حتى وقت',
            'generated_at' => 'وقت التحديث',
            'orders' => 'الطلبات',
            'recognized_orders' => 'الطلبات المحتسبة',
            'gross_value' => 'إجمالي القيمة',
            'recognized_revenue' => 'الإيراد المعترف به',
            'currency' => 'العملة',
            'store_count' => 'عدد المتاجر',
            'active_stores' => 'المتاجر النشطة',
            'active_customers' => 'العملاء النشطون',
            'selling_products' => 'المنتجات المباعة',
            'quantity_sold' => 'الكمية المباعة',
            'product_revenue' => 'إيراد المنتجات',
            'late_after_minutes' => 'يُعد متأخرًا بعد (دقيقة)',
            'late_orders' => 'الطلبات المتأخرة',
            'cancelled_orders' => 'الطلبات الملغية',
            'cancellation_rate_percent' => 'نسبة الإلغاء %',
            'cancelled_value' => 'قيمة الطلبات الملغية',
            'driver_count' => 'عدد السائقين',
            'online' => 'متصلون',
            'available' => 'متاحون',
            'busy' => 'مشغولون',
            'assignment_count' => 'عدد التكليفات',
            'threshold' => 'حد التنبيه',
            'alert_count' => 'عدد تنبيهات المخزون',
            'found' => 'تم العثور',
            'order_id' => 'رقم الطلب',
            'order_number' => 'رقم الطلب الظاهر',
            'store_id' => 'رقم المتجر',
            'channel' => 'القناة',
            'status' => 'الحالة',
            'grand_total' => 'الإجمالي',
            'customer_id' => 'رقم العميل',
            'customer' => 'العميل',
            'product_id' => 'رقم المنتج',
            'sku' => 'SKU',
            'revenue' => 'الإيراد',
            'total' => 'الإجمالي',
        ];

        if ($locale === 'ar' && isset($arabic[$key])) {
            return $arabic[$key];
        }

        return Str::headline($key);
    }

    private function channel(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $channel = strtolower($value);

        return in_array($channel, ['b2b', 'b2c'], true) ? $channel : null;
    }

    private function positiveInt(mixed $value): ?int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($integer) && $integer > 0 ? $integer : null;
    }
}
