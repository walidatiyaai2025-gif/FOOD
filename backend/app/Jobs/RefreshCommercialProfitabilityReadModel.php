<?php

namespace App\Jobs;

use App\Enterprise\Reporting\CommercialProfitabilityProjectionRefresher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class RefreshCommercialProfitabilityReadModel implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 45;

    public function __construct(
        public readonly int $storeId,
        public readonly int $afterId = 0,
        public readonly int $limit = 50,
    ) {
        $this->onQueue('enterprise-bi');
    }

    public function handle(CommercialProfitabilityProjectionRefresher $refresher): void
    {
        $result = $refresher->refreshStore(
            $this->storeId,
            $this->afterId,
            $this->limit,
        );

        if ($result['has_more'] && $result['next_cursor'] > $this->afterId) {
            self::dispatch(
                $this->storeId,
                $result['next_cursor'],
                $this->limit,
            );
        }
    }
}
