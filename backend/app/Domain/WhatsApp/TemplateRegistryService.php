<?php

namespace App\Domain\WhatsApp;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TemplateRegistryService
{
    /** @param array<string, mixed> $data */
    public function upsert(array $data, ?int $actorUserId = null): int
    {
        $providerName = trim((string) ($data['provider_name'] ?? ''));
        $locale = strtolower(trim((string) ($data['locale'] ?? 'en')));
        $status = strtolower(trim((string) ($data['provider_status'] ?? 'pending')));

        if ($providerName === '' || ! preg_match('/^[a-z0-9_\.\-]+$/', $providerName)) {
            throw ValidationException::withMessages(['provider_name' => ['Invalid provider template name.']]);
        }
        if (! in_array($status, ['pending', 'approved', 'rejected', 'paused', 'disabled'], true)) {
            throw ValidationException::withMessages(['provider_status' => ['Invalid template status.']]);
        }

        $now = now();
        $existing = DB::table('whats_app_templates')
            ->where('provider_name', $providerName)
            ->where('locale', $locale)
            ->first();

        $values = [
            'name' => mb_substr(trim((string) ($data['name'] ?? $providerName)), 0, 120),
            'category' => mb_substr(strtolower(trim((string) ($data['category'] ?? 'marketing'))), 0, 32),
            'provider_status' => $status,
            'body_preview' => isset($data['body_preview']) ? trim((string) $data['body_preview']) : null,
            'components' => isset($data['components']) && is_array($data['components'])
                ? json_encode($data['components'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'last_synced_at' => $now,
            'updated_at' => $now,
        ];

        if ($existing !== null) {
            DB::table('whats_app_templates')->where('id', $existing->id)->update($values);

            return (int) $existing->id;
        }

        return (int) DB::table('whats_app_templates')->insertGetId($values + [
            'provider_name' => $providerName,
            'locale' => $locale,
            'created_by' => $actorUserId,
            'created_at' => $now,
        ]);
    }

    public function approved(int $templateId): object
    {
        $template = DB::table('whats_app_templates')->where('id', $templateId)->first();

        if ($template === null || ! $template->is_active || $template->provider_status !== 'approved') {
            throw ValidationException::withMessages([
                'template_id' => ['Campaign requires an active approved template.'],
            ]);
        }

        return $template;
    }
}
