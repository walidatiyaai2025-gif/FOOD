<?php

namespace Tests\Feature;

use App\Models\MobileReleaseArtifact;
use App\Services\MobileReleaseArtifactMirror;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class MobileReleaseArtifactMirrorTest extends TestCase
{
    use RefreshDatabase;

    private string $version = '9.8.7';

    protected function setUp(): void
    {
        parent::setUp();
        File::deleteDirectory(storage_path('app/private/mobile-releases/'.$this->version));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/private/mobile-releases/'.$this->version));
        parent::tearDown();
    }

    public function test_mirror_downloads_verifies_and_publishes_all_three_apps(): void
    {
        $payloads = [
            'customer' => ['label' => 'Customer', 'bytes' => 'customer-apk-bytes'],
            'driver' => ['label' => 'Driver', 'bytes' => 'driver-apk-bytes'],
            'van' => ['label' => 'Van', 'bytes' => 'van-apk-bytes'],
        ];

        $manifestApps = [];
        $responses = [];

        foreach ($payloads as $app => $item) {
            $filename = 'FOODEX-'.$item['label'].'-'.$this->version.'.apk';
            $manifestApps[] = [
                'app' => $app,
                'version' => $this->version,
                'file' => $filename,
                'bytes' => strlen($item['bytes']),
                'sha256' => hash('sha256', $item['bytes']),
            ];
            $responses[$this->releaseUrl($filename)] = Http::response($item['bytes']);
        }

        $responses[$this->releaseUrl('LATEST_RELEASE.json')] = Http::response([
            'version' => $this->version,
            'source_commit' => str_repeat('c', 40),
            'android_apps' => $manifestApps,
        ]);

        Http::fake($responses);

        app(MobileReleaseArtifactMirror::class)->syncVersion($this->version);

        foreach ($payloads as $app => $item) {
            $artifact = MobileReleaseArtifact::query()
                ->where('app', $app)
                ->where('version', $this->version)
                ->firstOrFail();

            $this->assertSame('ready', $artifact->status);
            $this->assertSame(strlen($item['bytes']), $artifact->expected_bytes);
            $this->assertSame(strlen($item['bytes']), $artifact->downloaded_bytes);
            $this->assertSame(hash('sha256', $item['bytes']), $artifact->sha256);
            $this->assertNotNull($artifact->ready_at);
            $this->assertNotNull($artifact->local_path);

            $path = storage_path('app/private/'.$artifact->local_path);
            $this->assertFileExists($path);
            $this->assertSame($item['bytes'], file_get_contents($path));
            $this->assertFileDoesNotExist($path.'.part');
        }

        Http::assertSentCount(4);
    }

    public function test_checksum_mismatch_never_publishes_partial_file(): void
    {
        $badBytes = 'corrupt-customer';

        Http::fake([
            $this->releaseUrl('LATEST_RELEASE.json') => Http::response([
                'version' => $this->version,
                'source_commit' => str_repeat('d', 40),
                'android_apps' => [
                    [
                        'app' => 'customer',
                        'version' => $this->version,
                        'file' => 'FOODEX-Customer-'.$this->version.'.apk',
                        'bytes' => strlen($badBytes),
                        'sha256' => str_repeat('a', 64),
                    ],
                    [
                        'app' => 'driver',
                        'version' => $this->version,
                        'file' => 'FOODEX-Driver-'.$this->version.'.apk',
                        'bytes' => 1,
                        'sha256' => hash('sha256', 'd'),
                    ],
                    [
                        'app' => 'van',
                        'version' => $this->version,
                        'file' => 'FOODEX-Van-'.$this->version.'.apk',
                        'bytes' => 1,
                        'sha256' => hash('sha256', 'v'),
                    ],
                ],
            ]),
            $this->releaseUrl('FOODEX-Customer-'.$this->version.'.apk') => Http::response($badBytes),
        ]);

        try {
            app(MobileReleaseArtifactMirror::class)->syncVersion($this->version);
            $this->fail('Expected checksum verification failure.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('checksum', strtolower($exception->getMessage()));
        }

        $artifact = MobileReleaseArtifact::query()
            ->where('app', 'customer')
            ->where('version', $this->version)
            ->firstOrFail();

        $this->assertSame('failed', $artifact->status);
        $this->assertNull($artifact->local_path);

        $final = storage_path('app/private/mobile-releases/'.$this->version.'/FOODEX-Customer-'.$this->version.'.apk');
        $this->assertFileDoesNotExist($final);
        $this->assertFileDoesNotExist($final.'.part');
    }

    private function releaseUrl(string $file): string
    {
        return 'https://github.com/walidatiyaai2025-gif/FOOD/releases/download/v'.$this->version.'/'.$file;
    }
}
