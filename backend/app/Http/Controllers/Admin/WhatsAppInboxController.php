<?php

namespace App\Http\Controllers\Admin;

use App\Domain\SmartOrdering\WhatsApp\SmartOrderingConversationContextService;
use App\Domain\WhatsApp\AgentAssignmentEngine;
use App\Domain\WhatsApp\ConversationService;
use App\Domain\WhatsApp\OutboundMessageDispatcher;
use App\Domain\WhatsApp\ProviderTransportException;
use App\Domain\WhatsApp\RuntimePolicy;
use App\Domain\WhatsApp\WhatsAppMediaService;
use App\Http\Controllers\Controller;
use App\Models\PlatformCustomer;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\OperationalTenantScope;
use App\Support\AdminNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class WhatsAppInboxController extends Controller
{
    public function __construct(
        private readonly OperationalTenantScope $scope,
        private readonly AdminNavigation $navigation,
        private readonly AgentAssignmentEngine $agents,
        private readonly ConversationService $conversations,
        private readonly SmartOrderingConversationContextService $smartOrderingContext,
        private readonly OutboundMessageDispatcher $outbound,
        private readonly WhatsAppMediaService $media,
        private readonly RuntimePolicy $policy,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        $actor = $this->actor($request);
        $allowedStoreIds = $this->authorizedStoreIds($actor);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in([
                'OPEN',
                'WAITING_FOR_AGENT',
                'WAITING_FOR_CUSTOMER',
                'RESOLVED',
                'CLOSED',
            ])],
            'assignment' => ['nullable', Rule::in(['all', 'mine', 'unassigned'])],
            'store_id' => ['nullable', 'integer', 'min:1'],
            'channel' => ['nullable', 'string', 'max:24'],
            'conversation' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = $this->conversationQuery($actor, $allowedStoreIds);

        if (($filters['q'] ?? '') !== '') {
            $search = trim((string) $filters['q']);
            $query->where(function (Builder $nested) use ($search): void {
                $nested
                    ->where('customers.name', 'like', '%'.$search.'%')
                    ->orWhere('customers.email', 'like', '%'.$search.'%')
                    ->orWhere('customers.phone', 'like', '%'.$search.'%');
            });
        }

        if (! empty($filters['status'])) {
            $query->where('conversations.status', $filters['status']);
        }

        $assignment = $filters['assignment'] ?? 'all';
        if ($assignment === 'mine') {
            $query->where('conversations.primary_agent_id', $actor->getKey());
        } elseif ($assignment === 'unassigned') {
            $query->whereNull('conversations.primary_agent_id');
        }

        if (! empty($filters['store_id'])) {
            $storeId = (int) $filters['store_id'];
            abort_unless(in_array($storeId, $allowedStoreIds, true), 404);
            $query->where('conversations.store_id', $storeId);
        }

        if (! empty($filters['channel'])) {
            $query->where('conversations.channel', (string) $filters['channel']);
        }

        $conversationList = $query
            ->orderByRaw('CASE WHEN conversations.primary_agent_id IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('conversations.priority')
            ->orderByRaw('CASE WHEN conversations.waiting_since IS NULL THEN 1 ELSE 0 END')
            ->orderBy('conversations.waiting_since')
            ->orderByDesc('conversations.updated_at')
            ->paginate(40)
            ->withQueryString();

        $selectedId = isset($filters['conversation'])
            ? (int) $filters['conversation']
            : (($conversationList->first()->id ?? null) === null
                ? null
                : (int) $conversationList->first()->id);

        $selected = null;
        $timeline = collect();
        $tags = collect();
        $allTags = collect();
        $customer360 = null;
        $smartOrdering = ['orders' => [], 'timeline' => collect()];
        $agents = collect();

        if ($selectedId !== null) {
            $this->conversations->conversationForActor(
                $selectedId,
                $actor,
                'whatsapp.conversations.view',
            );

            $selected = $this->conversationQuery($actor, $allowedStoreIds)
                ->where('conversations.id', $selectedId)
                ->firstOrFail();

            $timeline = $this->timeline($selectedId);
            $tags = DB::table('whats_app_tags as tags')
                ->join('whats_app_conversation_tag as links', 'links.tag_id', '=', 'tags.id')
                ->where('links.conversation_id', $selectedId)
                ->orderBy('tags.name')
                ->get(['tags.id', 'tags.code', 'tags.name']);

            $allTags = DB::table('whats_app_tags')
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'code', 'name']);

            $customer360 = $this->customerContext(
                $selected->platform_customer_id === null
                    ? null
                    : (int) $selected->platform_customer_id,
                $allowedStoreIds,
                $actor,
            );

            $smartOrdering = $this->smartOrderingContext->forConversation(
                $selectedId,
                $allowedStoreIds,
            );
            $timeline = $timeline
                ->concat($smartOrdering['timeline'])
                ->sortBy(fn (array $row): string => (string) $row['timestamp'])
                ->values();

            $agents = $this->agents->summaries(
                $selected->store_id === null ? null : (int) $selected->store_id,
            );
        }

        $stores = DB::table('stores')
            ->whereIn('id', $allowedStoreIds)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        return view('admin.whatsapp-inbox', [
            'user' => $actor,
            'navGroups' => $this->navigation->groupsFor($actor),
            'navContext' => 'whatsapp_inbox',
            'conversations' => $conversationList,
            'selected' => $selected,
            'timeline' => $timeline,
            'tags' => $tags,
            'allTags' => $allTags,
            'customer360' => $customer360,
            'smartOrderingContext' => $smartOrdering,
            'agents' => $agents,
            'stores' => $stores,
            'filters' => $filters,
            'profile' => DB::table('whats_app_agent_profiles')
                ->where('user_id', $actor->getKey())
                ->first(),
            'canSupervise' => $actor->hasRole('SUPER_ADMIN')
                || $actor->hasPermission('whatsapp.customer_service.supervise'),
            'selectedIsResolved' => $selected !== null
                && in_array($selected->status, ['RESOLVED', 'CLOSED'], true),
            'transitionAction' => $selected !== null
                && in_array($selected->status, ['RESOLVED', 'CLOSED'], true)
                    ? 'reopen'
                    : 'resolve',
            'callsEnabled' => $this->policy->allowsCallWebhooks(),
            'inboundCallsEnabled' => $this->policy->allowsInboundCalls(),
            'canDialCalls' => $actor->hasPermission(
                'whatsapp.calls.dial',
                $selected?->store_id === null ? null : (int) $selected->store_id,
            ),
            'canManageCalls' => $actor->hasPermission(
                'whatsapp.calls.manage',
                $selected?->store_id === null ? null : (int) $selected->store_id,
            ),
        ]);
    }

    public function reply(Request $request, int $conversation): RedirectResponse
    {
        $actor = $this->actor($request);
        $validated = $request->validate([
            'body' => ['nullable', 'string', 'max:5000', 'required_without:attachment'],
            'attachment' => [
                'nullable',
                'file',
                'max:'.max(1024, (int) config('whatsapp.media.max_outbound_mb', 16) * 1024),
            ],
        ]);

        $this->conversations->conversationForActor(
            $conversation,
            $actor,
            'whatsapp.conversations.reply',
        );

        $attachmentMetadata = [];
        $attachment = $request->file('attachment');
        if ($attachment instanceof UploadedFile) {
            // conversationForActor inside appendOutboundPending remains authoritative;
            // storing first only creates a private file that is never publicly addressable.
            $attachmentMetadata = $this->media->storeOutbound($attachment, $conversation);
        }

        $messageId = $this->conversations->appendOutboundPending(
            $conversation,
            $actor,
            trim((string) ($validated['body'] ?? '')),
            $attachmentMetadata,
        );
        $this->outbound->queue($messageId);

        return $this->backToConversation(
            $conversation,
            __('whatsapp.inbox.flash.reply_queued'),
        );
    }

    public function attachment(Request $request, int $conversation, int $message): BinaryFileResponse
    {
        $actor = $this->actor($request);
        $this->conversations->conversationForActor(
            $conversation,
            $actor,
            'whatsapp.conversations.view',
        );

        $row = DB::table('whats_app_messages')
            ->where('id', $message)
            ->where('conversation_id', $conversation)
            ->first();
        abort_unless($row !== null, 404);

        try {
            $asset = $this->media->materialize($row);
        } catch (ProviderTransportException $exception) {
            abort($exception->retryable ? 503 : 404);
        }

        abort_unless(
            $asset['disk'] === 'local'
                && Storage::disk('local')->exists($asset['path']),
            404,
        );

        $headers = [
            'Content-Type' => $asset['mime_type'],
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ];

        $response = response()->file(
            Storage::disk('local')->path($asset['path']),
            $headers + [
                'Content-Disposition' => $this->contentDisposition(
                    $asset['media_type'],
                    $asset['filename'],
                ),
            ],
        );
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');

        return $response;
    }

    public function note(Request $request, int $conversation): RedirectResponse
    {
        $actor = $this->actor($request);
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $this->conversations->addInternalNote(
            $conversation,
            $actor,
            (string) $validated['body'],
        );

        return $this->backToConversation(
            $conversation,
            __('whatsapp.inbox.flash.note_saved'),
        );
    }

    public function assign(Request $request, int $conversation): RedirectResponse
    {
        $actor = $this->actor($request);
        $validated = $request->validate([
            'agent_id' => ['required', 'integer', 'min:1'],
            'backup_agent_id' => ['nullable', 'integer', 'min:1'],
            'expected_version' => ['required', 'integer', 'min:0'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $this->conversations->assign(
            $conversation,
            $actor,
            (int) $validated['agent_id'],
            (int) $validated['expected_version'],
            $validated['reason'] ?? null,
            isset($validated['backup_agent_id'])
                ? (int) $validated['backup_agent_id']
                : null,
        );

        return $this->backToConversation(
            $conversation,
            __('whatsapp.inbox.flash.assignment_updated'),
        );
    }

    public function transition(Request $request, int $conversation): RedirectResponse
    {
        $actor = $this->actor($request);
        $validated = $request->validate([
            'action' => ['required', Rule::in(['resolve', 'reopen'])],
        ]);

        $this->conversations->resolve(
            $conversation,
            $actor,
            $validated['action'] === 'reopen',
        );

        return $this->backToConversation(
            $conversation,
            $validated['action'] === 'reopen'
                ? __('whatsapp.inbox.flash.reopened')
                : __('whatsapp.inbox.flash.resolved'),
        );
    }

    public function updateMyStatus(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->authorizedStoreIds($actor);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['ONLINE', 'BUSY', 'OFFLINE', 'AWAY'])],
            'capacity' => ['required', 'integer', 'min:1', 'max:500'],
            'accepts_new' => ['required', 'boolean'],
        ]);

        $before = DB::table('whats_app_agent_profiles')
            ->where('user_id', $actor->getKey())
            ->first();

        $this->agents->setStatus(
            $actor,
            (string) $validated['status'],
            (int) $validated['capacity'],
            (bool) $validated['accepts_new'],
        );

        $after = DB::table('whats_app_agent_profiles')
            ->where('user_id', $actor->getKey())
            ->first();

        $this->audit->record(
            'whatsapp.agent_status_changed',
            $actor,
            null,
            $before === null ? null : (array) $before,
            $after === null ? null : (array) $after,
            $request,
        );

        return redirect()
            ->route('admin.whatsapp-inbox.index')
            ->with('status', __('whatsapp.inbox.flash.agent_status_updated'));
    }

    public function tag(Request $request, int $conversation): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->conversations->conversationForActor(
            $conversation,
            $actor,
            'whatsapp.internal_notes.manage',
        );

        $validated = $request->validate([
            'tag_id' => ['required', 'integer', 'min:1', 'exists:whats_app_tags,id'],
            'action' => ['required', Rule::in(['attach', 'detach'])],
        ]);

        if ($validated['action'] === 'attach') {
            DB::table('whats_app_conversation_tag')->insertOrIgnore([
                'conversation_id' => $conversation,
                'tag_id' => (int) $validated['tag_id'],
                'tagged_by' => $actor->getKey(),
                'created_at' => now(),
            ]);
        } else {
            DB::table('whats_app_conversation_tag')
                ->where('conversation_id', $conversation)
                ->where('tag_id', (int) $validated['tag_id'])
                ->delete();
        }

        return $this->backToConversation(
            $conversation,
            __('whatsapp.inbox.flash.tags_updated'),
        );
    }

    /** @param list<int> $allowedStoreIds */
    private function conversationQuery(User $actor, array $allowedStoreIds): Builder
    {
        $query = DB::table('whats_app_conversations as conversations')
            ->leftJoin('platform_customers as customers', 'customers.id', '=', 'conversations.platform_customer_id')
            ->leftJoin('stores', 'stores.id', '=', 'conversations.store_id')
            ->leftJoin('users as agents', 'agents.id', '=', 'conversations.primary_agent_id')
            ->select([
                'conversations.*',
                'customers.name as customer_name',
                'customers.email as customer_email',
                'customers.phone as customer_phone',
                'customers.origin_channel as customer_origin_channel',
                'stores.name as store_name',
                'stores.code as store_code',
                'agents.name as agent_name',
            ])
            ->selectSub(function (Builder $message): void {
                $message
                    ->from('whats_app_messages as preview_messages')
                    ->select('preview_messages.body')
                    ->whereColumn('preview_messages.conversation_id', 'conversations.id')
                    ->orderByDesc('preview_messages.occurred_at')
                    ->orderByDesc('preview_messages.id')
                    ->limit(1);
            }, 'last_message_body')
            ->selectSub(function (Builder $message): void {
                $message
                    ->from('whats_app_messages as preview_messages')
                    ->select('preview_messages.content_type')
                    ->whereColumn('preview_messages.conversation_id', 'conversations.id')
                    ->orderByDesc('preview_messages.occurred_at')
                    ->orderByDesc('preview_messages.id')
                    ->limit(1);
            }, 'last_message_type')
            ->selectSub(function (Builder $message): void {
                $message
                    ->from('whats_app_messages as preview_messages')
                    ->select('preview_messages.occurred_at')
                    ->whereColumn('preview_messages.conversation_id', 'conversations.id')
                    ->orderByDesc('preview_messages.occurred_at')
                    ->orderByDesc('preview_messages.id')
                    ->limit(1);
            }, 'last_message_at');

        if (! $actor->hasRole('SUPER_ADMIN')) {
            $query->whereIn('conversations.store_id', $allowedStoreIds);

            if (! $actor->hasPermission('whatsapp.customer_service.supervise')) {
                $query->where(function (Builder $visibility) use ($actor): void {
                    $visibility
                        ->whereNull('conversations.primary_agent_id')
                        ->orWhere('conversations.primary_agent_id', $actor->getKey());
                });
            }
        }

        return $query;
    }

    /** @return list<int> */
    private function authorizedStoreIds(User $actor): array
    {
        $ids = $this->scope->allowedStoreIds(
            $actor,
            'whatsapp.conversations.view',
        );

        abort_unless(
            $actor->hasPermission('whatsapp.inbox.view')
                || $ids !== [],
            403,
        );

        return $ids;
    }

    /** @return Collection<int, covariant array<string, mixed>> */
    private function timeline(int $conversationId): Collection
    {
        $messages = DB::table('whats_app_messages as messages')
            ->leftJoin('users', 'users.id', '=', 'messages.actor_user_id')
            ->where('messages.conversation_id', $conversationId)
            ->get([
                'messages.id',
                'messages.direction',
                'messages.content_type',
                'messages.actor_type',
                'messages.body',
                'messages.attachment_metadata',
                'messages.delivery_status',
                'messages.occurred_at',
                'users.name as actor_name',
            ])
            ->map(function (object $row) use ($conversationId): array {
                $metadata = $this->decodeAttachmentMetadata($row->attachment_metadata);
                $mediaType = strtolower(trim((string) ($metadata['media_type'] ?? $row->content_type ?? '')));
                $hasAttachment = in_array($mediaType, ['image', 'document', 'video', 'audio', 'sticker', 'media'], true);

                return [
                    'kind' => 'message',
                    'id' => (int) $row->id,
                    'direction' => (string) $row->direction,
                    'actor' => $row->actor_name
                        ?? ($row->actor_type === 'customer'
                            ? __('whatsapp.inbox.customer_actor')
                            : __('whatsapp.inbox.system_actor')),
                    'body' => $row->body,
                    'delivery_status' => $row->delivery_status,
                    'timestamp' => $row->occurred_at,
                    'attachment' => $hasAttachment ? [
                        'media_type' => $mediaType,
                        'mime_type' => (string) ($metadata['mime_type'] ?? ''),
                        'filename' => trim((string) ($metadata['filename'] ?? '')),
                        'url' => route('admin.whatsapp-inbox.attachments.show', [
                            'conversation' => $conversationId,
                            'message' => (int) $row->id,
                        ]),
                    ] : null,
                ];
            });

        $notes = DB::table('whats_app_internal_notes as notes')
            ->leftJoin('users', 'users.id', '=', 'notes.author_user_id')
            ->where('notes.conversation_id', $conversationId)
            ->get([
                'notes.id',
                'notes.body',
                'notes.created_at',
                'users.name as actor_name',
            ])
            ->map(static fn (object $row): array => [
                'kind' => 'note',
                'id' => (int) $row->id,
                'direction' => 'internal',
                'actor' => $row->actor_name ?? __('whatsapp.inbox.system_actor'),
                'body' => $row->body,
                'delivery_status' => null,
                'timestamp' => $row->created_at,
            ]);

        return $messages
            ->concat($notes)
            ->sortBy(fn (array $row): string => (string) $row['timestamp'])
            ->values();
    }

    /** @param list<int> $allowedStoreIds */
    private function customerContext(
        ?int $customerId,
        array $allowedStoreIds,
        User $actor,
    ): ?array {

        if ($customerId === null) {
            return null;
        }

        $customer = PlatformCustomer::query()
            ->with('originStore')
            ->find($customerId);

        if (! $customer instanceof PlatformCustomer) {
            return null;
        }

        $legacyCustomerId = (int) $customer->legacy_customer_id;

        $ordersBase = DB::table('orders')
            ->where('customer_id', $legacyCustomerId)
            ->whereIn('store_id', $allowedStoreIds);

        $financeStoreIds = array_values(array_intersect(
            $allowedStoreIds,
            $this->scope->allowedStoreIds($actor, 'finance.view'),
        ));

        $invoicesBase = DB::table('invoices')
            ->where('customer_id', $legacyCustomerId)
            ->whereIn('store_id', $financeStoreIds);

        $orders = (clone $ordersBase)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get([
                'id',
                'order_number',
                'store_id',
                'channel',
                'status',
                'currency',
                'grand_total',
                'created_at',
            ]);

        $invoices = (clone $invoicesBase)
            ->orderByDesc('issued_at')
            ->limit(5)
            ->get([
                'id',
                'invoice_number',
                'store_id',
                'channel',
                'status',
                'currency',
                'total',
                'issued_at',
            ]);

        $payments = DB::table('payments')
            ->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
            ->where('invoices.customer_id', $legacyCustomerId)
            ->whereIn('invoices.store_id', $financeStoreIds)
            ->orderByDesc('payments.created_at')
            ->limit(5)
            ->get([
                'payments.id',
                'payments.provider',
                'payments.status',
                'payments.amount',
                'payments.currency',
                'payments.created_at',
            ]);

        $ownership = DB::table('whats_app_customer_assignments as ownership')
            ->leftJoin('users as primary_agent', 'primary_agent.id', '=', 'ownership.primary_agent_id')
            ->leftJoin('users as backup_agent', 'backup_agent.id', '=', 'ownership.backup_agent_id')
            ->where('ownership.platform_customer_id', $customerId)
            ->first([
                'ownership.source',
                'ownership.assigned_at',
                'primary_agent.name as primary_agent_name',
                'backup_agent.name as backup_agent_name',
            ]);

        return [
            'customer' => $customer,
            'orders' => $orders,
            'invoices' => $invoices,
            'payments' => $payments,
            'orders_count' => (clone $ordersBase)->count(),
            'orders_value' => (float) (clone $ordersBase)->sum('grand_total'),
            'invoices_count' => (clone $invoicesBase)->count(),
            'ownership' => $ownership,
        ];
    }

    /** @return array<string,mixed> */
    private function decodeAttachmentMetadata(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function contentDisposition(string $mediaType, string $filename): string
    {
        $disposition = in_array($mediaType, ['image', 'video', 'audio', 'sticker'], true)
            ? 'inline'
            : 'attachment';
        $safe = str_replace(['"', "\r", "\n"], '', $filename);
        if ($safe === '') {
            $safe = 'whatsapp-attachment';
        }

        return $disposition.'; filename="'.addcslashes($safe, '\\\"').'"';
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

    private function backToConversation(int $conversation, string $status): RedirectResponse
    {
        return redirect()
            ->route('admin.whatsapp-inbox.index', ['conversation' => $conversation])
            ->with('status', $status);
    }
}
