<?php

namespace App\Domain\SmartOrdering;

use App\Domain\SmartOrdering\Models\SmartOrderingRevision;
use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SmartOrderingRevisionService
{
    public const SCHEMA_VERSION = 1;

    private const FORBIDDEN_PUBLIC_KEYS = [
        'id', 'store_id', 'site_id', 'tenant_id', 'branch_id', 'user_id',
        'created_by', 'published_by', 'scheduled_by',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    public function ensurePublished(SmartOrderingSite $site, ?User $actor = null, ?Request $request = null): SmartOrderingRevision
    {
        $published = $this->published($site);
        if ($published instanceof SmartOrderingRevision) {
            return $published;
        }

        return DB::transaction(function () use ($site, $actor, $request): SmartOrderingRevision {
            SmartOrderingSite::query()->whereKey($site->id)->lockForUpdate()->firstOrFail();
            $published = $this->published($site);
            if ($published instanceof SmartOrderingRevision) {
                return $published;
            }

            $revision = $this->createRevision($site, 'published', [
                'schema_version' => self::SCHEMA_VERSION,
                'site' => [
                    'slug' => (string) $site->slug,
                    'name_ar' => null,
                    'name_en' => (string) ($site->store()->value('name') ?? ''),
                ],
            ], $actor, null, true);

            if ($actor instanceof User) {
                $this->audit->record('smart_ordering.revision.initial_published', $actor, $revision, null, [
                    'store_id' => (int) $site->store_id,
                    'site_public_id' => (string) $site->public_id,
                    'revision_public_id' => (string) $revision->public_id,
                    'checksum' => (string) $revision->checksum,
                ], $request);
            }

            return $revision;
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

    public function scheduled(SmartOrderingSite $site): ?SmartOrderingRevision
    {
        return SmartOrderingRevision::query()
            ->where('site_id', $site->id)
            ->where('status', 'scheduled')
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->first();
    }

    public function createOrReuseDraft(
        User $actor,
        SmartOrderingSite $site,
        ?Request $request = null,
    ): SmartOrderingRevision {
        $this->assertManage($actor, $site);
        if (! SmartOrderingRevision::query()->where('site_id', $site->id)->exists()) {
            $this->ensurePublished($site, $actor, $request);
        }

        return DB::transaction(function () use ($actor, $site, $request): SmartOrderingRevision {
            SmartOrderingSite::query()->whereKey($site->id)->lockForUpdate()->firstOrFail();

            $draft = $this->draft($site);
            if ($draft instanceof SmartOrderingRevision) {
                return $draft;
            }

            $published = $this->published($site);
            $archived = $published instanceof SmartOrderingRevision
                ? null
                : SmartOrderingRevision::query()
                    ->where('site_id', $site->id)
                    ->where('status', 'archived')
                    ->latest('id')
                    ->first();
            $source = $this->scheduled($site) ?? $published ?? $archived;
            abort_unless($source instanceof SmartOrderingRevision, 409);

            $draft = $this->createRevision($site, 'draft', (array) $source->payload, $actor, (int) $source->id, false);
            $this->audit->record('smart_ordering.revision.draft_created', $actor, $draft, null, [
                'store_id' => (int) $site->store_id,
                'site_public_id' => (string) $site->public_id,
                'revision_public_id' => (string) $draft->public_id,
                'parent_revision_public_id' => (string) $source->public_id,
            ], $request);

            return $draft;
        });
    }

    public function createRollbackDraft(
        User $actor,
        SmartOrderingSite $site,
        SmartOrderingRevision $source,
        ?Request $request = null,
    ): SmartOrderingRevision {
        $this->assertManage($actor, $site);
        abort_unless((int) $source->site_id === (int) $site->id, 404);

        return DB::transaction(function () use ($actor, $site, $source, $request): SmartOrderingRevision {
            SmartOrderingSite::query()->whereKey($site->id)->lockForUpdate()->firstOrFail();

            $lockedSource = SmartOrderingRevision::query()
                ->whereKey($source->id)
                ->where('site_id', $site->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($lockedSource->status === 'archived', 409);
            abort_if($this->draft($site) instanceof SmartOrderingRevision, 409);

            $draft = $this->createRevision(
                $site,
                'draft',
                (array) $lockedSource->payload,
                $actor,
                (int) $lockedSource->id,
                false,
            );

            $this->audit->record('smart_ordering.revision.rollback_draft_created', $actor, $draft, null, [
                'store_id' => (int) $site->store_id,
                'site_public_id' => (string) $site->public_id,
                'revision_public_id' => (string) $draft->public_id,
                'rollback_source_revision_public_id' => (string) $lockedSource->public_id,
                'rollback_source_checksum' => (string) $lockedSource->checksum,
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

        $normalized = $this->validatePublicPayload($payload);
        $encoded = $this->encode($normalized);
        $before = (string) $revision->checksum;

        $revision->forceFill([
            'schema_version' => self::SCHEMA_VERSION,
            'payload' => $normalized,
            'checksum' => hash('sha256', $encoded),
            'created_by' => $actor->getKey(),
        ])->save();

        $this->audit->record('smart_ordering.revision.draft_updated', $actor, $revision, [
            'store_id' => (int) $site->store_id,
            'checksum' => $before,
        ], [
            'store_id' => (int) $site->store_id,
            'checksum' => (string) $revision->checksum,
        ], $request);

        return $revision->fresh();
    }

    public function schedule(
        User $actor,
        SmartOrderingRevision $revision,
        CarbonInterface $scheduledAt,
        ?Request $request = null,
    ): SmartOrderingRevision {
        $site = $revision->site()->firstOrFail();
        abort_unless($actor->hasPermission('smart_ordering.publish', (int) $site->store_id), 403);

        if (! $scheduledAt->isFuture()) {
            throw ValidationException::withMessages([
                'scheduled_at' => [__('smart_ordering_menu.validation.scheduled_future')],
            ]);
        }

        return DB::transaction(function () use ($actor, $revision, $site, $scheduledAt, $request): SmartOrderingRevision {
            SmartOrderingSite::query()->whereKey($site->id)->lockForUpdate()->firstOrFail();

            $draft = SmartOrderingRevision::query()
                ->whereKey($revision->id)
                ->where('site_id', $site->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($draft->status === 'draft', 409);

            $alreadyScheduled = SmartOrderingRevision::query()
                ->where('site_id', $site->id)
                ->where('status', 'scheduled')
                ->lockForUpdate()
                ->first();
            abort_if($alreadyScheduled instanceof SmartOrderingRevision, 409);

            $this->validatePublicPayload((array) $draft->payload);

            $draft->forceFill([
                'status' => 'scheduled',
                'scheduled_at' => $scheduledAt,
                'scheduled_by' => $actor->getKey(),
            ])->save();

            $this->audit->record('smart_ordering.revision.scheduled', $actor, $draft, null, [
                'store_id' => (int) $site->store_id,
                'site_public_id' => (string) $site->public_id,
                'revision_public_id' => (string) $draft->public_id,
                'checksum' => (string) $draft->checksum,
                'scheduled_at' => $draft->scheduled_at?->toISOString(),
            ], $request);

            return $draft->fresh();
        });
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

            $draft = SmartOrderingRevision::query()
                ->whereKey($revision->id)
                ->where('site_id', $site->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($draft->status === 'draft', 409);

            $pendingScheduled = SmartOrderingRevision::query()
                ->where('site_id', $site->id)
                ->where('status', 'scheduled')
                ->lockForUpdate()
                ->exists();
            abort_if($pendingScheduled, 409);

            return $this->publishLocked($actor, $site, $draft, $request);
        });
    }

    public function unpublish(
        User $actor,
        SmartOrderingSite $site,
        ?Request $request = null,
    ): ?SmartOrderingRevision {
        abort_unless($actor->hasPermission('smart_ordering.publish', (int) $site->store_id), 403);

        return DB::transaction(function () use ($actor, $site, $request): ?SmartOrderingRevision {
            SmartOrderingSite::query()->whereKey($site->id)->lockForUpdate()->firstOrFail();

            $published = SmartOrderingRevision::query()
                ->where('site_id', $site->id)
                ->where('status', 'published')
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if (! $published instanceof SmartOrderingRevision) {
                return null;
            }

            $published->forceFill(['status' => 'archived'])->save();

            $this->audit->record('smart_ordering.revision.unpublished', $actor, $published, [
                'store_id' => (int) $site->store_id,
                'revision_public_id' => (string) $published->public_id,
                'checksum' => (string) $published->checksum,
            ], [
                'store_id' => (int) $site->store_id,
                'revision_public_id' => null,
                'checksum' => null,
            ], $request);

            return $published->fresh();
        });
    }

    public function dispatchDueScheduled(int $limit = 25): int
    {
        $limit = max(1, min(100, $limit));
        $candidates = SmartOrderingRevision::query()
            ->where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'site_id']);

        $published = 0;

        foreach ($candidates as $candidate) {
            $didPublish = DB::transaction(function () use ($candidate): bool {
                $site = SmartOrderingSite::query()
                    ->whereKey((int) $candidate->site_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $scheduled = SmartOrderingRevision::query()
                    ->whereKey((int) $candidate->id)
                    ->where('site_id', $site->id)
                    ->where('status', 'scheduled')
                    ->whereNotNull('scheduled_at')
                    ->where('scheduled_at', '<=', now())
                    ->lockForUpdate()
                    ->first();

                if (! $scheduled instanceof SmartOrderingRevision) {
                    return false;
                }

                $actor = $scheduled->scheduled_by === null
                    ? null
                    : User::query()->find((int) $scheduled->scheduled_by);

                if (! $actor instanceof User
                    || ! $actor->is_active
                    || ! $actor->hasPermission('smart_ordering.publish', (int) $site->store_id)) {
                    return false;
                }

                $this->publishLocked($actor, $site, $scheduled);

                return true;
            });

            if ($didPublish) {
                $published++;
            }
        }

        return $published;
    }

    private function publishLocked(
        User $actor,
        SmartOrderingSite $site,
        SmartOrderingRevision $revision,
        ?Request $request = null,
    ): SmartOrderingRevision {
        abort_unless(in_array($revision->status, ['draft', 'scheduled'], true), 409);
        if ($revision->status === 'scheduled') {
            abort_unless($revision->scheduled_at !== null && $revision->scheduled_at->lte(now()), 409);
        }

        $this->validatePublicPayload((array) $revision->payload);

        $previous = SmartOrderingRevision::query()
            ->where('site_id', $site->id)
            ->where('status', 'published')
            ->lockForUpdate()
            ->latest('id')
            ->first();

        SmartOrderingRevision::query()
            ->where('site_id', $site->id)
            ->where('status', 'published')
            ->update(['status' => 'archived', 'updated_at' => now()]);

        $revision->forceFill([
            'status' => 'published',
            'published_by' => $actor->getKey(),
            'published_at' => now(),
        ])->save();

        $this->audit->record('smart_ordering.revision.published', $actor, $revision, [
            'store_id' => (int) $site->store_id,
            'revision_public_id' => $previous?->public_id,
            'checksum' => $previous?->checksum,
        ], [
            'store_id' => (int) $site->store_id,
            'revision_public_id' => (string) $revision->public_id,
            'checksum' => (string) $revision->checksum,
            'scheduled_at' => $revision->scheduled_at?->toISOString(),
        ], $request);

        return $revision->fresh();
    }

    /** @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function validatePublicPayload(array $payload): array
    {
        $this->assertNoInternalIdentifiers($payload);
        $payload['schema_version'] = self::SCHEMA_VERSION;
        $normalized = $this->canonicalize($payload);
        $encoded = $this->encode($normalized);

        if (strlen($encoded) > 262144) {
            throw ValidationException::withMessages([
                'payload' => ['Smart Ordering public configuration may not exceed 256 KiB.'],
            ]);
        }

        return $normalized;
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
        $normalized = $this->validatePublicPayload($payload);
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

    private function assertManage(User $actor, SmartOrderingSite $site): void
    {
        abort_unless($actor->hasPermission('smart_ordering.manage', (int) $site->store_id), 403);
    }
}
