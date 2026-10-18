<?php

namespace App\Domain\Assistant\Knowledge;

use App\Domain\Assistant\Contracts\AssistantToolInterface;
use App\Domain\Assistant\Data\AssistantToolRequest;
use App\Domain\Assistant\Data\AssistantToolResult;

final class AssistantKnowledgeTool implements AssistantToolInterface
{
    public const KEY = 'knowledge.search';

    public function __construct(private readonly AssistantKnowledgeIndex $index) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function execute(AssistantToolRequest $request): AssistantToolResult
    {
        $query = is_string($request->entities['query'] ?? null)
            ? trim((string) $request->entities['query'])
            : '';
        $maxResults = is_int($request->entities['max_results'] ?? null)
            ? (int) $request->entities['max_results']
            : null;

        $result = $this->index->retrieve(
            $query,
            $request->storeId,
            $request->locale,
            $maxResults,
        );

        $references = array_map(
            static fn (array $source): array => [
                'type' => 'knowledge_source',
                'public_id' => $source['public_id'],
                'source_key' => $source['source_key'],
                'source_type' => $source['source_type'],
                'title' => $source['title'],
                'version' => $source['version'],
                'scope' => $source['scope'],
                'source_uri' => $source['source_uri'],
                'approved_at' => $source['approved_at'],
                'indexed_at' => $source['indexed_at'],
            ],
            $result['sources'],
        );

        return new AssistantToolResult(
            data: $result,
            references: $references,
        );
    }
}
