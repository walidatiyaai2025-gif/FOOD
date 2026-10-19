<?php

namespace App\Domain\Assistant\Knowledge;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class AssistantKnowledgeIndex
{
    private const SOURCE_TYPES = [
        'manual',
        'policy',
        'procedure',
        'sop',
        'faq',
        'product',
        'business',
    ];

    /**
     * @param  array<string,mixed>  $document
     * @return array<string,mixed>
     */
    public function indexApproved(array $document): array
    {
        $sourceKey = $this->requiredString($document, 'source_key', 120);
        $sourceType = strtolower($this->requiredString($document, 'source_type', 32));
        $title = $this->requiredString($document, 'title', 255);
        $version = $this->requiredString($document, 'version', 80);
        $content = $this->requiredString($document, 'content', 2_000_000);
        $locale = $this->locale($document['locale'] ?? 'en');
        $storeId = $this->storeId($document['store_id'] ?? null);
        $sourceUri = $this->optionalString($document['source_uri'] ?? null, 1000);
        $validUntil = $this->dateTime($document['valid_until'] ?? null, 'valid_until');
        $approvedAt = $this->dateTime($document['approved_at'] ?? null, 'approved_at') ?? CarbonImmutable::now();
        $metadata = is_array($document['metadata'] ?? null) ? $document['metadata'] : [];

        if (! in_array($sourceType, self::SOURCE_TYPES, true)) {
            throw ValidationException::withMessages([
                'source_type' => ['Unsupported Assistant knowledge source type.'],
            ]);
        }

        if ($storeId !== null && ! DB::table('stores')->where('id', $storeId)->exists()) {
            throw ValidationException::withMessages([
                'store_id' => ['Assistant knowledge store scope does not exist.'],
            ]);
        }

        $chunks = $this->chunks($content);
        $now = CarbonImmutable::now();

        return DB::transaction(function () use (
            $sourceKey,
            $sourceType,
            $title,
            $version,
            $content,
            $locale,
            $storeId,
            $sourceUri,
            $validUntil,
            $approvedAt,
            $metadata,
            $chunks,
            $now,
        ): array {
            $scope = DB::table('assistant_knowledge_documents')
                ->where('source_key', $sourceKey)
                ->where('locale', $locale);
            $this->applyStoreScope($scope, $storeId);

            $supersededIds = (clone $scope)
                ->where('status', 'approved')
                ->where('version', '<>', $version)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            if ($supersededIds !== []) {
                DB::table('assistant_knowledge_documents')
                    ->whereIn('id', $supersededIds)
                    ->update([
                        'status' => 'superseded',
                        'updated_at' => $now,
                    ]);
                DB::table('assistant_knowledge_chunks')
                    ->whereIn('document_id', $supersededIds)
                    ->delete();
            }

            $existing = (clone $scope)
                ->where('version', $version)
                ->first(['id', 'public_id']);

            $attributes = [
                'store_id' => $storeId,
                'source_key' => $sourceKey,
                'source_type' => $sourceType,
                'title' => $title,
                'version' => $version,
                'locale' => $locale,
                'source_uri' => $sourceUri,
                'status' => 'approved',
                'content_hash' => hash('sha256', $content),
                'approved_at' => $approvedAt,
                'indexed_at' => $now,
                'valid_until' => $validUntil,
                'revoked_at' => null,
                'deleted_at' => null,
                'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => $now,
            ];

            if ($existing === null) {
                $publicId = (string) Str::uuid();
                $documentId = (int) DB::table('assistant_knowledge_documents')->insertGetId([
                    ...$attributes,
                    'public_id' => $publicId,
                    'created_at' => $now,
                ]);
            } else {
                $documentId = (int) $existing->id;
                $publicId = (string) $existing->public_id;
                DB::table('assistant_knowledge_documents')
                    ->where('id', $documentId)
                    ->update($attributes);
                DB::table('assistant_knowledge_chunks')
                    ->where('document_id', $documentId)
                    ->delete();
            }

            foreach ($chunks as $sequence => $chunk) {
                DB::table('assistant_knowledge_chunks')->insert([
                    'document_id' => $documentId,
                    'sequence' => $sequence,
                    'content' => $chunk,
                    'search_text' => $this->normalize($chunk),
                    'content_hash' => hash('sha256', $chunk),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return [
                'document_id' => $documentId,
                'public_id' => $publicId,
                'source_key' => $sourceKey,
                'version' => $version,
                'store_id' => $storeId,
                'status' => 'approved',
                'chunk_count' => count($chunks),
                'indexed_at' => $now->toIso8601String(),
            ];
        });
    }

    public function revoke(string $sourceKey, ?int $storeId = null, ?string $version = null): int
    {
        return $this->retire($sourceKey, $storeId, $version, 'revoked');
    }

    public function deleteSource(string $sourceKey, ?int $storeId = null, ?string $version = null): int
    {
        return $this->retire($sourceKey, $storeId, $version, 'deleted');
    }

    /**
     * @return array<string,mixed>
     */
    public function retrieve(
        string $query,
        ?int $storeId,
        string $locale,
        ?int $maxResults = null,
    ): array {
        $locale = $this->locale($locale);
        $normalizedQuery = $this->normalize($query);
        $queryTokens = $this->tokens($normalizedQuery);
        $maxResults = min(
            8,
            max(1, $maxResults ?? (int) config('assistant.knowledge.max_results', 5)),
        );

        if ($queryTokens === []) {
            return $this->emptyResult($storeId, 'WEAK_QUERY');
        }

        $candidateLimit = min(500, max(20, (int) config('assistant.knowledge.candidate_limit', 200)));
        $threshold = min(1.0, max(0.05, (float) config('assistant.knowledge.retrieval_threshold', 0.45)));
        $now = CarbonImmutable::now();

        $candidates = DB::table('assistant_knowledge_chunks as chunks')
            ->join('assistant_knowledge_documents as docs', 'docs.id', '=', 'chunks.document_id')
            ->where('docs.status', 'approved')
            ->whereNull('docs.revoked_at')
            ->whereNull('docs.deleted_at')
            ->where('docs.locale', $locale)
            ->where(function ($builder) use ($now): void {
                $builder->whereNull('docs.valid_until')
                    ->orWhere('docs.valid_until', '>=', $now);
            })
            ->where(function ($builder) use ($storeId): void {
                $builder->whereNull('docs.store_id');
                if ($storeId !== null) {
                    $builder->orWhere('docs.store_id', $storeId);
                }
            })
            ->orderByDesc('docs.updated_at')
            ->orderBy('chunks.sequence')
            ->limit($candidateLimit)
            ->get([
                'chunks.id as chunk_id',
                'chunks.sequence',
                'chunks.content',
                'chunks.search_text',
                'docs.public_id',
                'docs.store_id',
                'docs.source_key',
                'docs.source_type',
                'docs.title',
                'docs.version',
                'docs.locale',
                'docs.source_uri',
                'docs.approved_at',
                'docs.indexed_at',
                'docs.valid_until',
            ]);

        $matches = [];
        foreach ($candidates as $candidate) {
            $score = $this->score(
                $queryTokens,
                $normalizedQuery,
                (string) $candidate->search_text,
                (string) $candidate->title,
                $storeId,
                $candidate->store_id === null ? null : (int) $candidate->store_id,
            );

            if ($score < $threshold) {
                continue;
            }

            $matches[] = [
                'score' => round($score, 4),
                'excerpt' => mb_substr(trim((string) $candidate->content), 0, 900),
                'untrusted_document_text' => true,
                'source' => $this->source($candidate),
            ];
        }

        usort($matches, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);
        $matches = array_slice($matches, 0, $maxResults);

        if ($matches === []) {
            return $this->emptyResult($storeId, 'NO_STRONG_EVIDENCE');
        }

        $sources = [];
        foreach ($matches as $match) {
            $source = $match['source'];
            $sources[$source['public_id']] = $source;
        }

        $sources = array_values($sources);
        $indexedAt = collect($sources)
            ->pluck('indexed_at')
            ->filter()
            ->sortDesc()
            ->first();

        return [
            'found' => true,
            'answerable' => true,
            'matches' => $matches,
            'sources' => $sources,
            'store_ids' => $storeId === null ? [] : [$storeId],
            'freshness' => [
                'state' => 'fresh',
                'indexed_at' => $indexedAt,
                'source_count' => count($sources),
            ],
            'retrieval' => [
                'threshold' => $threshold,
                'candidate_limit' => $candidateLimit,
                'max_results' => $maxResults,
                'prompt_instructions_trusted' => false,
            ],
        ];
    }

    private function retire(
        string $sourceKey,
        ?int $storeId,
        ?string $version,
        string $status,
    ): int {
        $sourceKey = trim($sourceKey);
        if ($sourceKey === '') {
            throw ValidationException::withMessages([
                'source_key' => ['Assistant knowledge source key is required.'],
            ]);
        }

        $now = CarbonImmutable::now();

        return DB::transaction(function () use ($sourceKey, $storeId, $version, $status, $now): int {
            $query = DB::table('assistant_knowledge_documents')
                ->where('source_key', $sourceKey);
            $this->applyStoreScope($query, $storeId);

            if ($version !== null && trim($version) !== '') {
                $query->where('version', trim($version));
            }

            $ids = $query
                ->whereNotIn('status', ['deleted'])
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            if ($ids === []) {
                return 0;
            }

            $changes = [
                'status' => $status,
                'updated_at' => $now,
            ];

            if ($status === 'revoked') {
                $changes['revoked_at'] = $now;
            }

            if ($status === 'deleted') {
                $changes['deleted_at'] = $now;
            }

            DB::table('assistant_knowledge_documents')
                ->whereIn('id', $ids)
                ->update($changes);
            DB::table('assistant_knowledge_chunks')
                ->whereIn('document_id', $ids)
                ->delete();

            return count($ids);
        });
    }

    /**
     * @return array<string,mixed>
     */
    private function source(object $row): array
    {
        return [
            'public_id' => (string) $row->public_id,
            'source_key' => (string) $row->source_key,
            'source_type' => (string) $row->source_type,
            'title' => (string) $row->title,
            'version' => (string) $row->version,
            'locale' => (string) $row->locale,
            'scope' => $row->store_id === null ? 'global' : 'tenant',
            'store_id' => $row->store_id === null ? null : (int) $row->store_id,
            'source_uri' => $row->source_uri === null ? null : (string) $row->source_uri,
            'approved_at' => (string) $row->approved_at,
            'indexed_at' => (string) $row->indexed_at,
            'valid_until' => $row->valid_until === null ? null : (string) $row->valid_until,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyResult(?int $storeId, string $reason): array
    {
        return [
            'found' => false,
            'answerable' => false,
            'reason' => $reason,
            'matches' => [],
            'sources' => [],
            'store_ids' => $storeId === null ? [] : [$storeId],
            'freshness' => [
                'state' => 'unavailable',
                'source_count' => 0,
            ],
            'retrieval' => [
                'threshold' => (float) config('assistant.knowledge.retrieval_threshold', 0.45),
                'prompt_instructions_trusted' => false,
            ],
        ];
    }

    /**
     * @param  list<string>  $queryTokens
     */
    private function score(
        array $queryTokens,
        string $normalizedQuery,
        string $searchText,
        string $title,
        ?int $requestedStoreId,
        ?int $documentStoreId,
    ): float {
        $chunkTokens = $this->tokens($searchText);
        if ($chunkTokens === []) {
            return 0.0;
        }

        $matched = count(array_intersect($queryTokens, $chunkTokens));
        $coverage = $matched / max(1, count($queryTokens));

        $titleTokens = $this->tokens($this->normalize($title));
        $titleCoverage = count(array_intersect($queryTokens, $titleTokens)) / max(1, count($queryTokens));
        $phraseBoost = $normalizedQuery !== '' && str_contains($searchText, $normalizedQuery) ? 0.12 : 0.0;
        $scopeBoost = $requestedStoreId !== null && $requestedStoreId === $documentStoreId ? 0.03 : 0.0;

        return min(1.0, ($coverage * 0.82) + ($titleCoverage * 0.12) + $phraseBoost + $scopeBoost);
    }

    /**
     * @return list<string>
     */
    private function chunks(string $content): array
    {
        $maxChars = min(4000, max(400, (int) config('assistant.knowledge.chunk_chars', 1200)));
        $overlap = min(300, max(0, (int) config('assistant.knowledge.chunk_overlap_chars', 120)));
        $content = preg_replace("/\r\n?|\n/u", "\n", trim($content)) ?? trim($content);
        $chunks = [];
        $length = mb_strlen($content);
        $offset = 0;

        while ($offset < $length) {
            $chunk = trim(mb_substr($content, $offset, $maxChars));
            if ($chunk !== '') {
                $chunks[] = $chunk;
            }

            $step = max(1, $maxChars - $overlap);
            $offset += $step;
        }

        return $chunks === [] ? [trim($content)] : $chunks;
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * @return list<string>
     */
    private function tokens(string $value): array
    {
        $stopWords = [
            'a', 'an', 'and', 'are', 'do', 'does', 'for', 'how', 'is', 'of', 'or', 'our', 'please', 'the', 'to', 'what',
            'او', 'أو', 'الى', 'إلى', 'عن', 'على', 'في', 'كيف', 'ما', 'ماذا', 'من', 'هو', 'هي', 'و',
        ];

        $tokens = preg_split('/\s+/u', $this->normalize($value)) ?: [];

        return array_values(array_unique(array_filter(
            $tokens,
            static fn (string $token): bool => mb_strlen($token) >= 2 && ! in_array($token, $stopWords, true),
        )));
    }

    private function requiredString(array $document, string $key, int $maxLength): string
    {
        $value = $document[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw ValidationException::withMessages([
                $key => ["Assistant knowledge [{$key}] is required."],
            ]);
        }

        $value = trim($value);
        if (mb_strlen($value) > $maxLength) {
            throw ValidationException::withMessages([
                $key => ["Assistant knowledge [{$key}] is too long."],
            ]);
        }

        return $value;
    }

    private function optionalString(mixed $value, int $maxLength): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || mb_strlen(trim($value)) > $maxLength) {
            throw ValidationException::withMessages([
                'source_uri' => ['Assistant knowledge source URI is invalid.'],
            ]);
        }

        return trim($value);
    }

    private function locale(mixed $value): string
    {
        if (! is_string($value) || ! in_array(strtolower(trim($value)), ['ar', 'en'], true)) {
            throw ValidationException::withMessages([
                'locale' => ['Assistant knowledge locale must be ar or en.'],
            ]);
        }

        return strtolower(trim($value));
    }

    private function storeId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $storeId = filter_var($value, FILTER_VALIDATE_INT);
        if (! is_int($storeId) || $storeId <= 0) {
            throw ValidationException::withMessages([
                'store_id' => ['Assistant knowledge store scope must be a positive integer.'],
            ]);
        }

        return $storeId;
    }

    private function dateTime(mixed $value, string $field): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return $value instanceof \DateTimeInterface
                ? CarbonImmutable::instance($value)
                : CarbonImmutable::parse((string) $value);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                $field => ["Assistant knowledge [{$field}] is not a valid date/time."],
            ]);
        }
    }

    private function applyStoreScope(Builder $query, ?int $storeId): void
    {
        if ($storeId === null) {
            $query->whereNull('store_id');

            return;
        }

        $query->where('store_id', $storeId);
    }
}
