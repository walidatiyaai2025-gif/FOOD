<?php

namespace App\Domain\SmartOrdering;

use App\Models\SmartOrderingRevision;
use App\Models\SmartOrderingSite;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SmartOrderingConfigurationService
{
    public const SCHEMA_VERSION = 1;

    private const FORBIDDEN_PUBLIC_KEYS = [
        'id', 'store_id', 'site_id', 'tenant_id', 'branch_id', 'user_id',
        'created_by', 'published_by',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    public function ensurePublished(
        SmartOrderingSite $site,
        ?User $actor = null,
        ?Request $request = null,
    ): SmartOrderingRevision {
        $current = $this->published($site);
        if ($current !== null) {
            return $current;
        }

        return DB::transaction(function () use ($site, $actor, $request): SmartOrderingRevision {
            SmartOrderingSite::query()->whereKey($site->getKey())->lockForUpdate()->firstOrFail();

            $existing = $this->published($site);
            if ($existing !== null) {
                return $existing;
            }

            $revision = $this->createRevision(
                site: $site,
                status: 'published',
                payload: ['schema_version' => self::SCHEMA_VERSION, 'settings' => []],
                actor: $actor,
                parentRevisionId: null,
                published: true,
            );

            $this->audit->record(
                'smart_ordering.revision.initial_published',
                $actor,
                $revision,
                null,
                $this->auditPayload($site, $revision),
                $request,
            );

            return $revision;
        });
    }

    public function published(SmartOrderingSite $site): ?SmartOrderingRevision
    {
        return SmartOrderingRevision::query()
            ->where('site_id', $site->getKey())
            ->where('status', 'published')
            ->latest('id')
            ->first();
    }

    public function createOrReuseDraft(
        User $actor,
        SmartOrderingSite $site,
        ?Request $request = null,
    ): SmartOrderingRevision {
        $this->ensurePublished($site, $actor, $request);

        return DB::transaction(function () use ($actor, $site, $request): SmartOrderingRevision {
            SmartOrderingSite::query()->whereKey($site->getKey())->lockForUpdate()->firstOrFail();

            $draft = SmartOrderingRevision::query()
                ->where('site_id', $site->getKey())
                ->where('status', 'draft')
                ->latest('id')
                ->first();

            if ($draft !== null) {
                return $draft;
            }

            $published = $this->published($site);
            abort_unless($published !== null, 409);

            $draft = $this->createRevision(
                site: $site,
                status: 'draft',
                payload: (array) $published->payload,
                actor: $actor,
                parentRevisionId: $published->getKey(),
                published: false,
            );

            $this->audit->record(
                'smart_ordering.revision.draft_created',
                $actor,
                $draft,
                null,
                $this->auditPayload($site, $draft),
                $request,
            );

            return $draft;
        });
    }

    /** @param array<string,mixed> $payload */
    public function updateDraft(
        User $actor,
        SmartOrderingSite $site,
        SmartOrderingRevision $revision,
        array $payload,
        ?Request $request = null,
    ): SmartOrderingRevision {
        abort_unless((int) $revision->site_id === (int) $site->getKey(), 404);
        abort_unless($revision->status === 'draft', 409, 'Only a Smart Ordering draft can be edited.');

        $normalized = $this->validatePublicPayload($payload);
        $before = (string) $revision->checksum;
        $encoded = $this->encode($normalized);

        $revision->forceFill([
            'schema_version' => self::SCHEMA_VERSION,
            'payload' => $normalized,
            'checksum' => hash('sha256', $encoded),
            'created_by' => $actor->getKey(),
        ])->save();

        $this->audit->record(
            'smart_ordering.revision.draft_updated',
            $actor,
            $revision,
            ['store_id' => (int) $site->store_id, 'checksum' => $before],
            $this->auditPayload($site, $revision),
            $request,
        );

        return $revision->fresh();
    }

    public function publish(
        User $actor,
        SmartOrderingSite $site,
        SmartOrderingRevision $revision,
        ?Request $request = null,
    ): SmartOrderingRevision {
        abort_unless((int) $revision->site_id === (int) $site->getKey(), 404);

        return DB::transaction(function () use ($actor, $site, $revision, $request): SmartOrderingRevision {
            SmartOrderingSite::query()->whereKey($site->getKey())->lockForUpdate()->firstOrFail();

            $draft = SmartOrderingRevision::query()
                ->whereKey($revision->getKey())
                ->where('site_id', $site->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless($draft->status === 'draft', 409, 'Only a Smart Ordering draft can be published.');
            $this->validatePublicPayload((array) $draft->payload);

            $current = SmartOrderingRevision::query()
                ->where('site_id', $site->getKey())
                ->where('status', 'published')
                ->whereKeyNot($draft->getKey())
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if ($current !== null) {
                $current->forceFill(['status' => 'archived'])->save();
            }

            $draft->forceFill([
                'status' => 'published',
                'published_by' => $actor->getKey(),
                'published_at' => now(),
            ])->save();

            $this->audit->record(
                'smart_ordering.revision.published',
                $actor,
                $draft,
                $current === null ? null : $this->auditPayload($site, $current),
                $this->auditPayload($site, $draft),
                $request,
            );

            return $draft->fresh();
        });
    }

    /** @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function validatePublicPayload(array $payload): array
    {
        $this->assertNoInternalIdentifiers($payload);

        $payload['schema_version'] = self::SCHEMA_VERSION;
        $payload = $this->canonicalize($payload);
        $encoded = $this->encode($payload);

        if (strlen($encoded) > 262144) {
            throw ValidationException::withMessages([
                'payload' => ['Smart Ordering public configuration may not exceed 256 KiB.'],
            ]);
        }

        return $payload;
    }

    private function createRevision(
        SmartOrderingSite $site,
        string $status,
        array $payload,
        ?User $actor,
        ?int $parentRevisionId,
        bool $published,
    ): SmartOrderingRevision {
        $payload = $this->validatePublicPayload($payload);
        $encoded = $this->encode($payload);

        return SmartOrderingRevision::query()->create([
            'public_id' => (string) Str::uuid(),
            'site_id' => $site->getKey(),
            'status' => $status,
            'schema_version' => self::SCHEMA_VERSION,
            'payload' => $payload,
            'checksum' => hash('sha256', $encoded),
            'parent_revision_id' => $parentRevisionId,
            'created_by' => $actor?->getKey(),
            'published_by' => $published ? $actor?->getKey() : null,
            'published_at' => $published ? now() : null,
        ]);
    }

    /** @return array<string,mixed> */
    private function auditPayload(SmartOrderingSite $site, SmartOrderingRevision $revision): array
    {
        return [
            'store_id' => (int) $site->store_id,
            'site_public_id' => (string) $site->public_id,
            'revision_public_id' => (string) $revision->public_id,
            'status' => (string) $revision->status,
            'checksum' => (string) $revision->checksum,
        ];
    }

    private function assertNoInternalIdentifiers(mixed $value): void
    {
        if (! is_array($value)) {
            return;
        }

        foreach ($value as $key => $item) {
            if (is_string($key) && in_array(strtolower($key), self::FORBIDDEN_PUBLIC_KEYS, true)) {
                throw ValidationException::withMessages([
                    'payload' => ["Public Smart Ordering configuration cannot expose internal key [{$key}]."],
                ]);
            }

            $this->assertNoInternalIdentifiers($item);
        }
    }

    private function encode(array $payload): string
    {
        return json_encode(
            $this->canonicalize($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
