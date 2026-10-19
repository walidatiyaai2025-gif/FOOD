<?php

namespace App\Domain\Assistant\Performance;

use App\Domain\Analytics\ReadModel\AnalyticsFactMaterializer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class RefreshAssistantAnalyticsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 840;

    /** @param list<int> $storeIds */
    public function __construct(public array $storeIds = [])
    {
        $this->onQueue((string) config('assistant.performance.background_queue', 'assistant-background'));
    }

    public function handle(AnalyticsFactMaterializer $materializer): void
    {
        $storeIds = collect($this->storeIds)
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        $materializer->refreshIncremental($storeIds);
    }

    public function uniqueId(): string
    {
        $storeIds = $this->storeIds;
        sort($storeIds);

        return 'assistant-analytics:'.implode(',', $storeIds);
    }
}
