<?php

namespace App\Domain\WhatsApp;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ChatbotRuntimePolicy
{
    public function __construct(private readonly RuntimePolicy $whatsAppRuntime) {}

    /** @return array<string, mixed> */
    public function settings(): array
    {
        if (! Schema::hasTable('whats_app_chatbot_settings')) {
            return $this->defaults();
        }

        $row = DB::table('whats_app_chatbot_settings')->orderBy('id')->first();

        if ($row === null) {
            return $this->defaults();
        }

        return [
            'id' => (int) $row->id,
            'enabled' => (bool) $row->enabled,
            'emergency_stop' => (bool) $row->emergency_stop,
            'mode' => (string) $row->mode,
            'default_locale' => (string) $row->default_locale,
            'working_from' => $row->working_from,
            'working_to' => $row->working_to,
            'after_hours_handoff' => (bool) $row->after_hours_handoff,
            'welcome_ar' => $row->welcome_ar,
            'welcome_en' => $row->welcome_en,
            'fallback_ar' => $row->fallback_ar,
            'fallback_en' => $row->fallback_en,
            'ai_enabled' => (bool) $row->ai_enabled,
            'confidence_threshold' => (float) $row->confidence_threshold,
            'max_autonomous_steps' => (int) $row->max_autonomous_steps,
            'require_order_confirmation' => (bool) $row->require_order_confirmation,
            'human_handoff_enabled' => (bool) $row->human_handoff_enabled,
            'revision' => (int) $row->revision,
        ];
    }

    public function enabled(): bool
    {
        $settings = $this->settings();

        return (bool) $settings['enabled'] && ! (bool) $settings['emergency_stop'];
    }

    public function mode(): string
    {
        $mode = strtolower((string) $this->settings()['mode']);

        return in_array($mode, ['menu', 'ai', 'hybrid'], true) ? $mode : 'hybrid';
    }

    public function canHandleConversation(int $conversationId): bool
    {
        if (! $this->enabled() || ! $this->whatsAppRuntime->allowsInbound()) {
            return false;
        }

        $settings = $this->settings();
        if (! $this->withinWorkingHours($settings) && (bool) $settings['after_hours_handoff']) {
            return false;
        }

        if (! Schema::hasTable('whats_app_conversations')) {
            return false;
        }

        $conversation = DB::table('whats_app_conversations')->where('id', $conversationId)->first();
        if ($conversation === null) {
            return false;
        }

        if ($conversation->primary_agent_id !== null) {
            return false;
        }

        if (Schema::hasTable('whats_app_chatbot_sessions')) {
            $session = DB::table('whats_app_chatbot_sessions')
                ->where('conversation_id', $conversationId)
                ->first();

            if ($session !== null && ((bool) $session->human_takeover || (string) $session->state === 'PAUSED')) {
                return false;
            }
        }

        $recipient = $conversation->external_participant_phone ?? null;
        if ($conversation->platform_customer_id !== null) {
            $recipient = DB::table('platform_customers')
                ->where('id', $conversation->platform_customer_id)
                ->value('phone') ?? $recipient;
        }

        return is_string($recipient)
            && $recipient !== ''
            && $this->whatsAppRuntime->allowsOutbound($recipient, Capability::AUTOMATION);
    }

    /** @param array<string, mixed> $settings */
    private function withinWorkingHours(array $settings): bool
    {
        $from = $settings['working_from'] ?? null;
        $to = $settings['working_to'] ?? null;
        if (! is_string($from) || ! is_string($to) || trim($from) === '' || trim($to) === '') {
            return true;
        }

        $now = now();
        $current = $now->format('H:i');
        $from = substr($from, 0, 5);
        $to = substr($to, 0, 5);

        if ($from === $to) {
            return true;
        }

        if ($from < $to) {
            return $current >= $from && $current < $to;
        }

        return $current >= $from || $current < $to;
    }

    /** @return array<string, mixed> */
    private function defaults(): array
    {
        return [
            'id' => null,
            'enabled' => false,
            'emergency_stop' => false,
            'mode' => 'hybrid',
            'default_locale' => 'ar',
            'working_from' => null,
            'working_to' => null,
            'after_hours_handoff' => true,
            'welcome_ar' => 'أهلاً بك في مساعد FOODEX الذكي.',
            'welcome_en' => 'Welcome to the FOODEX smart assistant.',
            'fallback_ar' => 'اختر خدمة من القائمة أو اطلب التحدث مع موظف.',
            'fallback_en' => 'Choose a service or ask for an agent.',
            'ai_enabled' => true,
            'confidence_threshold' => 0.650,
            'max_autonomous_steps' => 4,
            'require_order_confirmation' => true,
            'human_handoff_enabled' => true,
            'revision' => 0,
        ];
    }
}
