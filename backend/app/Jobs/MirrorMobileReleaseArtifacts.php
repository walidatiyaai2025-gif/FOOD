<?php

namespace App\Jobs;

use App\Services\MobileReleaseArtifactMirror;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class MirrorMobileReleaseArtifacts implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 4;

    public int $timeout = 1800;

    public bool $failOnTimeout = true;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public int $uniqueFor = 3600;

    public function __construct(public readonly string $version) {}

    public function uniqueId(): string
    {
        return 'mobile-release-artifacts:'.$this->version;
    }

    public function handle(MobileReleaseArtifactMirror $mirror): void
    {
        $mirror->syncVersion($this->version);
    }
}
