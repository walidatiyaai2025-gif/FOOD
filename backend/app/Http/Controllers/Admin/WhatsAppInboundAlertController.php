<?php

namespace App\Http\Controllers\Admin;

use App\Domain\WhatsApp\ConversationService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class WhatsAppInboundAlertController extends Controller
{
    public function __construct(private readonly ConversationService $conversations) {}

    public function feed(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $validated = $request->validate([
            'after' => ['nullable', 'integer', 'min:0'],
        ]);
        $after = (int) ($validated['after'] ?? 0);

        $rows = DB::table('whats_app_inbound_alerts as alerts')
            ->join('whats_app_messages as messages', 'messages.id', '=', 'alerts.message_id')
            ->join('whats_app_conversations as conversations', 'conversations.id', '=', 'alerts.conversation_id')
            ->leftJoin('platform_customers as customers', 'customers.id', '=', 'conversations.platform_customer_id')
            ->leftJoin('stores', 'stores.id', '=', 'conversations.store_id')
            ->where('alerts.recipient_user_id', $actor->getKey())
            ->whereIn('alerts.state', ['pending', 'delivered'])
            ->when($after > 0, fn ($query) => $query->where('alerts.id', '>', $after))
            ->orderBy('messages.occurred_at')
            ->orderBy('alerts.id')
            ->limit(20)
            ->get([
                'alerts.id as alert_id',
                'alerts.state',
                'alerts.message_id',
                'alerts.conversation_id',
                'messages.body',
                'messages.content_type',
                'messages.occurred_at',
                'conversations.store_id',
                'conversations.primary_agent_id',
                'conversations.external_participant_phone',
                'customers.name as customer_name',
                'customers.phone as customer_phone',
                'stores.name as store_name',
            ]);

        $cursor = $after;
        $data = [];

        foreach ($rows as $row) {
            $cursor = max($cursor, (int) $row->alert_id);

            try {
                $this->conversations->conversationForActor(
                    (int) $row->conversation_id,
                    $actor,
                    'whatsapp.conversations.view',
                );
            } catch (HttpExceptionInterface) {
                DB::table('whats_app_inbound_alerts')
                    ->where('id', $row->alert_id)
                    ->where('recipient_user_id', $actor->getKey())
                    ->whereIn('state', ['pending', 'delivered'])
                    ->update([
                        'state' => 'suppressed',
                        'seen_at' => now(),
                        'updated_at' => now(),
                    ]);
                continue;
            }

            if ((string) $row->state === 'pending') {
                DB::table('whats_app_inbound_alerts')
                    ->where('id', $row->alert_id)
                    ->where('state', 'pending')
                    ->update([
                        'state' => 'delivered',
                        'delivered_at' => now(),
                        'updated_at' => now(),
                    ]);
            }

            $phone = trim((string) ($row->customer_phone ?: $row->external_participant_phone ?: ''));
            $name = trim((string) ($row->customer_name ?? ''));
            $occurredAt = $row->occurred_at === null
                ? null
                : Carbon::parse((string) $row->occurred_at)->toIso8601String();

            $data[] = [
                'alert_id' => (int) $row->alert_id,
                'message_id' => (int) $row->message_id,
                'conversation_id' => (int) $row->conversation_id,
                'customer_name' => $name !== '' ? $name : $phone,
                'customer_phone' => $phone,
                'preview' => $this->preview($row->content_type, $row->body),
                'content_type' => strtolower(trim((string) ($row->content_type ?? 'unknown'))),
                'store_name' => $row->store_name,
                'occurred_at' => $occurredAt,
                'url' => route('admin.whatsapp-inbox.index', [
                    'conversation' => (int) $row->conversation_id,
                ]),
            ];
        }

        return response()->json([
            'data' => $data,
            'meta' => ['cursor' => $cursor],
        ]);
    }

    public function dismiss(Request $request, int $alert): JsonResponse
    {
        $actor = $this->actor($request);
        $row = $this->alertForActor($actor, $alert);

        DB::table('whats_app_inbound_alerts')
            ->where('id', $alert)
            ->where('recipient_user_id', $actor->getKey())
            ->whereIn('state', ['pending', 'delivered'])
            ->update([
                'state' => 'dismissed',
                'seen_at' => now(),
                'dismissed_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json([
            'ok' => true,
            'alert_id' => $alert,
            'conversation_id' => (int) $row->conversation_id,
        ]);
    }

    public function open(Request $request, int $alert): JsonResponse
    {
        $actor = $this->actor($request);
        $row = $this->alertForActor($actor, $alert);

        DB::table('whats_app_inbound_alerts')
            ->where('id', $alert)
            ->where('recipient_user_id', $actor->getKey())
            ->whereIn('state', ['pending', 'delivered'])
            ->update([
                'state' => 'opened',
                'seen_at' => now(),
                'opened_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json([
            'ok' => true,
            'alert_id' => $alert,
            'conversation_id' => (int) $row->conversation_id,
            'url' => route('admin.whatsapp-inbox.index', [
                'conversation' => (int) $row->conversation_id,
            ]),
        ]);
    }

    private function alertForActor(User $actor, int $alert): object
    {
        $row = DB::table('whats_app_inbound_alerts')
            ->where('id', $alert)
            ->where('recipient_user_id', $actor->getKey())
            ->first();

        abort_unless($row !== null, 404);

        $this->conversations->conversationForActor(
            (int) $row->conversation_id,
            $actor,
            'whatsapp.conversations.view',
        );

        return $row;
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

    private function preview(mixed $contentType, mixed $body): string
    {
        $type = strtolower(trim((string) ($contentType ?? 'unknown')));

        if ($type === 'text') {
            $clean = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) ($body ?? ''))));
            if ($clean !== '') {
                return Str::limit($clean, 180);
            }
        }

        return match ($type) {
            'image', 'photo' => __('whatsapp.inbound_popup.photo_received'),
            'document', 'file' => __('whatsapp.inbound_popup.document_received'),
            'audio', 'voice', 'voice_note' => __('whatsapp.inbound_popup.voice_received'),
            'video' => __('whatsapp.inbound_popup.video_received'),
            'sticker', 'media' => __('whatsapp.inbound_popup.media_received'),
            default => __('whatsapp.inbound_popup.message_received'),
        };
    }
}
