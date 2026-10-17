<?php

namespace App\Domain\SmartOrdering\Themes;

use App\Domain\SmartOrdering\Models\SmartOrderingRevision;
use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Domain\SmartOrdering\SmartOrderingRevisionService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class RestaurantTemplateSelectionService
{
    public function __construct(
        private readonly ThemeRegistry $themes,
        private readonly SmartOrderingRevisionService $revisions,
    ) {}

    public function selectDraft(
        User $actor,
        SmartOrderingSite $site,
        string $templateId,
        ?Request $request = null,
    ): SmartOrderingRevision {
        $templateId = strtolower(trim($templateId));
        if (! in_array($templateId, $this->themes->productionIds(), true)) {
            throw ValidationException::withMessages([
                'template' => ['Unsupported production restaurant template.'],
            ]);
        }

        $definition = $this->themes->productionDefinition($templateId);
        $draft = $this->revisions->createOrReuseDraft($actor, $site, $request);
        $payload = (array) $draft->payload;
        $appearance = is_array($payload['appearance'] ?? null)
            ? $payload['appearance']
            : [];

        $appearance['template'] = $definition['id'];
        $appearance['template_version'] = (int) $definition['version'];
        $payload['appearance'] = $appearance;

        return $this->revisions->updateDraft($actor, $draft, $payload, $request);
    }

    /** @return array{id:string,version:int}|null */
    public function selected(SmartOrderingRevision $revision): ?array
    {
        $appearance = is_array($revision->payload['appearance'] ?? null)
            ? $revision->payload['appearance']
            : [];
        $id = strtolower(trim((string) ($appearance['template'] ?? '')));
        $version = $appearance['template_version'] ?? null;

        if (! in_array($id, $this->themes->productionIds(), true) || ! is_numeric($version)) {
            return null;
        }

        $definition = $this->themes->productionDefinition($id, $version);
        if (($definition['fallback'] ?? false) === true) {
            return null;
        }

        return ['id' => $definition['id'], 'version' => (int) $definition['version']];
    }
}
