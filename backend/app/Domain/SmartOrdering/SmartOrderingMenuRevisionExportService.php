<?php

namespace App\Domain\SmartOrdering;

use App\Domain\SmartOrdering\Models\SmartOrderingRevision;
use App\Domain\SmartOrdering\Models\SmartOrderingSite;

final class SmartOrderingMenuRevisionExportService
{
    public const SCHEMA_VERSION = 1;

    public function __construct(
        private readonly SmartOrderingMenuRevisionCompareService $snapshots,
    ) {}

    /**
     * @return array{
     *   schema_version:int,
     *   site_public_id:string,
     *   revision:array{public_id:string,schema_version:int,status:string,checksum:string},
     *   content_checksum:string,
     *   content:array{records:list<array{
     *     entity:string,
     *     public_id:string,
     *     branch_public_id:?string,
     *     attributes:array<string,mixed>
     *   }>}
     * }
     */
    public function export(SmartOrderingSite $site, SmartOrderingRevision $revision): array
    {
        abort_unless((int) $revision->site_id === (int) $site->id, 404);

        $content = [
            'records' => array_values($this->snapshots->snapshot($site, $revision)),
        ];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'site_public_id' => (string) $site->public_id,
            'revision' => [
                'public_id' => (string) $revision->public_id,
                'schema_version' => (int) $revision->schema_version,
                'status' => (string) $revision->status,
                'checksum' => (string) $revision->checksum,
            ],
            'content_checksum' => hash('sha256', $this->encode($content)),
            'content' => $content,
        ];
    }

    public function json(SmartOrderingSite $site, SmartOrderingRevision $revision): string
    {
        return $this->encode($this->export($site, $revision));
    }

    /** @param array<mixed> $value */
    private function encode(array $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }
}
