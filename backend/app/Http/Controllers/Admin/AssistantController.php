<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Assistant\Contracts\AssistantBrainInterface;
use App\Domain\Assistant\Data\AssistantBrainRequest;
use App\Domain\Assistant\Data\AssistantToolRequest;
use App\Domain\Assistant\Data\AssistantToolResult;
use App\Domain\Assistant\Models\AssistantConversation;
use App\Domain\Assistant\Models\AssistantConversationState;
use App\Domain\Assistant\Models\AssistantMessage;
use App\Domain\Assistant\Support\AssistantPageContext;
use App\Domain\Assistant\Tools\AssistantToolRegistry;
use App\Http\Controllers\Controller;
use App\Models\User;
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
        private readonly AssistantBrainInterface $brain,
        private readonly AssistantToolRegistry $tools,
        private readonly AssistantPageContext $pageContext,
        private readonly AssistantRuntimeSettings $runtimeSettings,
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
        $locale = $this->locale($data['locale'] ?? $conversation->locale ?? $user->locale);
        $context = $this->pageContext->resolve($user, (array) ($data['context'] ?? []));
        $correlationId = (string) Str::uuid();

        try {
            $payload = DB::transaction(function () use ($user, $conversation, $data, $locale, $context, $correlationId): array {
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
                    'content' => trim((string) $data['message']),
                    'correlation_id' => $correlationId,
                ]);

                $brainResult = $this->brain->respond(new AssistantBrainRequest(
                    message: trim((string) $data['message']),
                    locale: $locale,
                    context: $context,
                    state: $this->brainState($state),
                ));

                $toolResult = null;
                $toolRequest = null;
                $pendingClarification = (bool) ($brainResult->state['pending_clarification'] ?? false);

                if ($brainResult->intent !== null && ! $pendingClarification) {
                    abort_unless($this->tools->has($brainResult->intent), 422);
                    $entities = $this->toolEntities($brainResult->state);
                    $toolRequest = new AssistantToolRequest(
                        actorUserId: (int) $user->getKey(),
                        locale: $locale,
                        channel: $this->channel($entities['channel'] ?? $context['channel'] ?? null),
                        storeId: $this->positiveInt($entities['store_id'] ?? $context['store_id'] ?? null),
                        entities: $entities,
                        context: $context,
                    );
                    $toolResult = $this->tools->get($brainResult->intent)->execute($toolRequest);
                }

                $cards = $toolResult === null ? $brainResult->cards : $this->cards($brainResult->intent, $locale, $toolResult);
                $actions = $toolResult === null ? $this->actions($brainResult->actions) : $this->actions($toolResult->actions);
                $suggested = $brainResult->suggestedPrompts === [] ? $this->suggestedPrompts($locale) : $brainResult->suggestedPrompts;
                $content = $toolResult === null ? $brainResult->message : $this->resultMessage($brainResult->intent, $locale);
                $assistantPayload = [
                    'cards' => $cards,
                    'actions' => $actions,
                    'suggested_prompts' => $suggested,
                    'data' => $toolResult === null ? [] : $toolResult->data,
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
                    'title' => $locked->title ?: Str::limit(trim((string) $data['message']), 120, ''),
                    'last_message_at' => now(),
                ])->save();

                return [
                    'conversation' => $this->conversationPayload($locked->fresh()),
                    'assistant_message' => $this->messagePayload($assistantMessage),
                    'suggested_prompts' => $suggested,
                ];
            });
        } catch (HttpExceptionInterface|ValidationException $exception) {
            throw $exception;
        } catch (InvalidArgumentException $exception) {
            return response()->json(['error' => [
                'code' => 'ASSISTANT_REQUEST_INVALID',
                'message' => $locale === 'ar' ? 'تعذر تنفيذ هذا الطلب بالمدخلات الحالية.' : 'This request cannot be completed with the current inputs.',
            ]], 422);
        } catch (Throwable $exception) {
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

    /** @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function toolEntities(array $state): array
    {
        $entities = is_array($state['entities'] ?? null) ? $state['entities'] : [];
        $period = is_array($state['period'] ?? null) ? $state['period'] : null;

        if ($period !== null && isset($period['start'], $period['end'])) {
            $entities['from'] = CarbonImmutable::parse((string) $period['start'])->setTimezone('Asia/Kuwait')->toDateString();
            $entities['to'] = CarbonImmutable::parse((string) $period['end'])->subSecond()->setTimezone('Asia/Kuwait')->toDateString();
        }

        return $entities;
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
        $authorized = is_array($context['authorized_entities'] ?? null) ? $context['authorized_entities'] : [];

        if ($toolRequest?->storeId !== null) {
            $authorized['store_id'] = $toolRequest->storeId;
        }
        if ($toolRequest?->channel !== null) {
            $authorized['channel'] = $toolRequest->channel;
        }

        $references = $toolResult === null ? [] : $toolResult->references;

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
                'last_intent' => $brainState['last_intent'] ?? null,
                'last_period' => is_array($brainState['last_period'] ?? null) ? $brainState['last_period'] : null,
                'last_authorized_entities' => $authorized,
                'last_tool' => $toolResult === null ? null : ($brainState['last_tool'] ?? $brainState['last_intent'] ?? null),
                'result_references' => $toolResult === null ? [] : $toolResult->references,
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
        ]];
    }

    private function resultMessage(?string $intent, string $locale): string
    {
        $label = $this->intentLabel($intent, $locale);

        return $locale === 'ar'
            ? 'تم تجهيز '.$label.' من بيانات FOODEX المعتمدة.'
            : $label.' is ready from authoritative FOODEX data.';
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
