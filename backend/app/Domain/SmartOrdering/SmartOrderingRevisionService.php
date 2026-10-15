<?php

namespace App\Domain\SmartOrdering;

use App\Domain\SmartOrdering\Models\SmartOrderingRevision;
use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SmartOrderingRevisionService
{
    public const SCHEMA_VERSION = 1;

    public function __construct(private readonly AuditLogger $audit) {}

    public function ensurePublished(SmartOrderingSite $site, ?User $actor = null): SmartOrderingRevision
    {
        $published = $this->published($site);
        if ($published instanceof SmartOrderingRevision) {
            return $published;
        }

        return DB::transaction(function () use ($site, $actor): SmartOrderingRevision {
            SmartOrderingSite::query()->whereKey($site->id)->lockForUpdate()->firstOrFail();
            $published = $this->published($site);
            if ($published instanceof SmartOrderingRevision) {
                return $published;
            }

            return $this->createRevision($site, 'published', [
                'schema_version' => self::SCHEMA_VERSION,
                'site' => [
                    'slug' => (string) $site->slug,
                    'name_ar' => null,
                    'name_en' => (string) ($site->store()->value('name') ?? ''),
                ],
            ], $actor, null, true);
        });
    }

    public function published(SmartOrderingSite $site): ?SmartOrderingRevision
    {
        return SmartOrderingRevision::query()
            ->where('site_id', $site->id)
            ->where('status', 'published')
            ->latest('id')
            ->first();
    }

    public function draft(SmartOrderingSite $site): ?SmartOrderingRevision
    {
        return SmartOrderingRevision::query()
            ->where('site_id', $site->id)
            ->where('status', 'draft')
            ->latest('id')
            ->first();
    }

    public function createOrReuseDraft(
        User $actor,
        SmartOrderingSite $site,
        ?Request $request = null,
    ): SmartOrderingRevision {
        $this->assertManage($actor, $site);
        $this->ensurePublished($site, $actor);

        return DB::transaction(function () use ($actor, $site, $request): SmartOrderingRevision {
            SmartOrderingSite::query()->whereKey($site->id)->lockForUpdate()->firstOrFail();

            $draft = $this->draft($site);
            if ($draft instanceof SmartOrderingRevision) {
                return $draft;
            }

            $published = $this->published($site);
            abort_unless($published instanceof SmartOrderingRevision, 409);

            $draft = $this->createRevision($site, 'draft', (array) $published->payload, $actor, (int) $published->id, false);
            $this->audit->record('smart_ordering.revision.draft_created', $actor, $draft, null, [
                'site_id' => $site->id,
                'parent_revision_id' => $published->id,
            ], $request);

            return $draft;
        });
    }

    /** @param array<string,mixed> $payload */
    public function updateDraft(
        User $actor,
        SmartOrderingRevision $revision,
        array $payload,
        ?Request $request = null,
    ): SmartOrderingRevision {
        $site = $revision->site()->firstOrFail();
        $this->assertManage($actor, $site);
        abort_unless($revision->status === 'draft', 409);

        $normalized = $this->canonicalize([...$payload, 'schema_version' => self::SCHEMA_VERSION]);
        $encoded = $this->encode($normalized);
        $before = (string) $revision->checksum;

        $revision->forceFill([
            'schema_version' => self::SCHEMA_VERSION,
            'payload' => $normalized,
            'checksum' => hash('sha256', $encoded),
            'created_by' => $actor->getKey(),
        ])->save();

        $this->audit->record('smart_ordering.revision.draft_updated', $actor, $revision, [
            'checksum' => $before,
        ], [
            'checksum' => $revision->checksum,
        ], $request);

        return $revision->fresh();
    }

    public function publish(
        User $actor,
        SmartOrderingRevision $revision,
        ?Request $request = null,
    ): SmartOrderingRevision {
        $site = $revision->site()->firstOrFail();
        abort_unless($actor->hasPermission('smart_ordering.publish', (int) $site->store_id), 403);

        return DB::transaction(function () use ($actor, $revision, $site, $request): SmartOrderingRevision {
            SmartOrderingSite::query()->whereKey($site->id)->lockForUpdate()->firstOrFail();
            $draft = SmartOrderingRevision::query()->whereKey($revision->id)->lockForUpdate()->firstOrFail();
            abort_unless($draft->status === 'draft', 409);

            SmartOrderingRevision::query()
                ->where('site_id', $site->id)
                ->where('status', 'published')
                ->update(['status' => 'archived', 'updated_at' => now()]);

            $draft->forceFill([
                'status' => 'published',
                'published_by' => $actor->getKey(),
                'published_at' => now(),
            ])->save();

            $this->audit->record('smart_ordering.revision.published', $actor, $draft, null, [
                'site_id' => $site->id,
                'checksum' => $draft->checksum,
            ], $request);

            return $draft->fresh();
        });
    }

    /** @param array<string,mixed> $payload */
    private function createRevision(
        SmartOrderingSite $site,
        string $status,
        array $payload,
        ?User $actor,
        ?int $parentRevisionId,
        bool $published,
    ): SmartOrderingRevision {
        $normalized = $this->canonicalize($payload);
        $encoded = $this->encode($normalized);

        return SmartOrderingRevision::query()->create([
            'public_id' => (string) Str::uuid(),
            'site_id' => $site->id,
            'status' => $status,
            'schema_version' => self::SCHEMA_VERSION,
            'payload' => $normalized,
            'checksum' => hash('sha256', $encoded),
            'parent_revision_id' => $parentRevisionId,
            'created_by' => $actor?->getKey(),
            'published_by' => $published ? $actor?->getKey() : null,
            'published_at' => $published ? now() : null,
        ]);
    }

    /** @param array<string,mixed> $payload */
    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

    private function assertManage(User $actor, SmartOrderingSite $site): void
    {
        abort_unless($actor->hasPermission('smart_ordering.manage', (int) $site->store_id), 403);
    }
}
