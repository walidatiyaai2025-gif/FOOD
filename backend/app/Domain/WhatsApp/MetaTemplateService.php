<?php

namespace App\Domain\WhatsApp;

use App\Models\WhatsAppConnectionProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

final class MetaTemplateService
{
    public function __construct(private readonly TemplateRegistryService $registry) {}

    /** @param array<string, mixed> $data
     * @return array{template_id:int,provider_status:string,provider_template_id:?string}
     */
    public function create(array $data, ?int $actorUserId = null): array
    {
        $providerName = $this->canonicalProviderName((string) ($data['provider_name'] ?? ''));
        $locale = strtolower(trim((string) ($data['locale'] ?? '')));
        $category = strtolower(trim((string) ($data['category'] ?? '')));
        $body = trim((string) ($data['body_preview'] ?? ''));

        if (preg_match('/^[a-z0-9_]+$/', $providerName) !== 1) {
            throw ValidationException::withMessages([
                'provider_name' => [__('whatsapp.campaigns.meta_template_name_invalid')],
            ]);
        }
        if (! in_array($locale, ['ar', 'en'], true)
            || ! in_array($category, ['marketing', 'utility'], true)
            || $body === '') {
            throw ValidationException::withMessages([
                'template' => [__('whatsapp.campaigns.meta_template_invalid')],
            ]);
        }
        if (str_contains($body, '{{') || str_contains($body, '}}')) {
            throw ValidationException::withMessages([
                'body_preview' => [__('whatsapp.campaigns.meta_template_variables_unsupported')],
            ]);
        }

        $profile = $this->primaryProfile();

        $payload = [
            'name' => $providerName,
            'language' => $this->providerLanguage($locale),
            'category' => strtoupper($category),
            'components' => [[
                'type' => 'BODY',
                'text' => $body,
            ]],
        ];

        try {
            $response = Http::withToken($this->token($profile))
                ->acceptJson()
                ->asJson()
                ->timeout($this->timeout())
                ->post($this->graphUrl($profile, (string) $profile->provider_account_id).'/message_templates', $payload);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'template' => [__('whatsapp.campaigns.meta_template_provider_unavailable')],
            ]);
        }

        if (! $response->successful()) {
            throw ValidationException::withMessages([
                'template' => [__('whatsapp.campaigns.meta_template_provider_rejected')],
            ]);
        }

        $providerTemplateId = $this->nullableString($response->json('id'));
        if ($providerTemplateId === null) {
            throw ValidationException::withMessages([
                'template' => [__('whatsapp.campaigns.meta_template_provider_rejected')],
            ]);
        }

        $status = $this->status((string) $response->json('status', 'pending'));
        $templateId = $this->registry->upsert([
            'name' => trim((string) ($data['name'] ?? $providerName)),
            'provider_name' => $providerName,
            'locale' => $locale,
            'category' => $category,
            'provider_status' => $status,
            'body_preview' => $body,
            'components' => $payload['components'],
            'is_active' => true,
        ], $actorUserId);

        return [
            'template_id' => $templateId,
            'provider_status' => $status,
            'provider_template_id' => $providerTemplateId,
        ];
    }

    /** @return array{fetched:int,updated:int,imported:int,approved:int,pending:int,rejected:int} */
    public function sync(): array
    {
        $profile = $this->primaryProfile();
        $endpoint = $this->graphUrl($profile, (string) $profile->provider_account_id).'/message_templates';

        $fetched = 0;
        $updated = 0;
        $imported = 0;
        $approved = 0;
        $pending = 0;
        $rejected = 0;
        $after = null;
        $seenCursors = [];
        $pages = 0;

        do {
            $query = [
                'fields' => 'id,name,status,category,language,components',
                'limit' => 100,
            ];
            if ($after !== null) {
                $query['after'] = $after;
            }

            try {
                $response = Http::withToken($this->token($profile))
                    ->acceptJson()
                    ->timeout($this->timeout())
                    ->get($endpoint, $query);
            } catch (Throwable) {
                throw ValidationException::withMessages([
                    'template' => [__('whatsapp.campaigns.meta_template_provider_unavailable')],
                ]);
            }

            if (! $response->successful()) {
                throw ValidationException::withMessages([
                    'template' => [__('whatsapp.campaigns.meta_template_sync_failed')],
                ]);
            }

            $remote = $response->json('data');
            $remote = is_array($remote) ? $remote : [];

            foreach ($remote as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $providerName = $this->canonicalProviderName((string) ($item['name'] ?? ''));
                $locale = $this->localLocale($item['language'] ?? null);
                if ($providerName === '' || $locale === null) {
                    continue;
                }

                $fetched++;
                $local = DB::table('whats_app_templates')
                    ->where('provider_name', $providerName)
                    ->where('locale', $locale)
                    ->first();

                $status = $this->status((string) ($item['status'] ?? 'pending'));
                $category = strtolower((string) ($item['category'] ?? ($local->category ?? 'marketing')));
                $components = is_array($item['components'] ?? null) ? $item['components'] : [];
                $body = $this->bodyFromComponents($components) ?? (string) ($local->body_preview ?? '');

                $this->registry->upsert([
                    'name' => $local === null ? $providerName : (string) $local->name,
                    'provider_name' => $providerName,
                    'locale' => $locale,
                    'category' => $category,
                    'provider_status' => $status,
                    'body_preview' => $body,
                    'components' => $components,
                    'is_active' => $local === null ? true : (bool) $local->is_active,
                ], $local === null || $local->created_by === null ? null : (int) $local->created_by);

                if ($local === null) {
                    $imported++;
                } else {
                    $updated++;
                }

                if ($status === 'approved') {
                    $approved++;
                } elseif ($status === 'rejected') {
                    $rejected++;
                } else {
                    $pending++;
                }
            }

            $pages++;
            $nextUrl = $this->nullableString($response->json('paging.next'));
            $nextAfter = $this->nullableString($response->json('paging.cursors.after'));
            if ($nextUrl === null || $nextAfter === null || $nextAfter === $after || isset($seenCursors[$nextAfter])) {
                $after = null;
                continue;
            }

            $seenCursors[$nextAfter] = true;
            $after = $nextAfter;

            if ($pages >= 50 && $after !== null) {
                throw ValidationException::withMessages([
                    'template' => [__('whatsapp.campaigns.meta_template_sync_failed')],
                ]);
            }
        } while ($after !== null);

        return compact('fetched', 'updated', 'imported', 'approved', 'pending', 'rejected');
    }

    private function primaryProfile(): WhatsAppConnectionProfile
    {
        $profile = WhatsAppConnectionProfile::query()
            ->where('is_primary', true)
            ->where('status', ConnectionProfileStatus::ACTIVE->value)
            ->first();

        if (! $profile instanceof WhatsAppConnectionProfile
            || strtolower((string) $profile->provider_type) !== 'meta_cloud'
            || trim((string) $profile->provider_account_id) === ''
            || ! $profile->secretConfigured()) {
            throw ValidationException::withMessages([
                'template' => [__('whatsapp.campaigns.meta_template_connection_required')],
            ]);
        }

        return $profile;
    }

    private function token(WhatsAppConnectionProfile $profile): string
    {
        $token = $profile->credential_secret;

        if (! is_string($token) || trim($token) === '') {
            throw ValidationException::withMessages([
                'template' => [__('whatsapp.campaigns.meta_template_connection_required')],
            ]);
        }

        return trim($token);
    }

    private function canonicalProviderName(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[\s-]+/u', '_', $value) ?? '';
        $value = preg_replace('/[^a-z0-9_]+/', '', $value) ?? '';
        $value = preg_replace('/_+/', '_', $value) ?? '';

        return trim($value, '_');
    }

    private function providerLanguage(string $locale): string
    {
        return $locale === 'ar' ? 'ar' : 'en_US';
    }

    private function localLocale(mixed $language): ?string
    {
        $language = strtolower(trim((string) $language));
        if ($language === 'ar' || str_starts_with($language, 'ar_')) {
            return 'ar';
        }
        if ($language === 'en' || str_starts_with($language, 'en_')) {
            return 'en';
        }

        return null;
    }

    private function status(string $status): string
    {
        $status = strtolower(trim($status));

        return match ($status) {
            'approved' => 'approved',
            'rejected' => 'rejected',
            'paused' => 'paused',
            'disabled' => 'disabled',
            default => 'pending',
        };
    }

    /** @param array<int, mixed> $components */
    private function bodyFromComponents(array $components): ?string
    {
        foreach ($components as $component) {
            if (! is_array($component) || strtoupper((string) ($component['type'] ?? '')) !== 'BODY') {
                continue;
            }
            $text = $this->nullableString($component['text'] ?? null);
            if ($text !== null) {
                return $text;
            }
        }

        return null;
    }

    private function graphUrl(WhatsAppConnectionProfile $profile, string $resource): string
    {
        $base = rtrim((string) config('whatsapp.meta.base_url', 'https://graph.facebook.com'), '/');
        $metadata = $profile->getAttribute('provider_metadata');
        $metadata = is_array($metadata) ? $metadata : [];
        $version = trim((string) ($metadata['graph_version'] ?? config('whatsapp.meta.graph_version', 'v26.0')));
        if (preg_match('/^v\d+\.\d+$/', $version) !== 1) {
            $version = 'v26.0';
        }

        return $base.'/'.$version.'/'.rawurlencode($resource);
    }

    private function timeout(): int
    {
        return max(1, min(30, (int) config('whatsapp.meta.timeout_seconds', 10)));
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 500);
    }
}
