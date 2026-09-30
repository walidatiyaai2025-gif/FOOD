<?php

namespace Tests\Unit;

use App\Domain\Updater\UpdatePackageInspector;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class UpdatePackageInspectorTest extends TestCase
{
    public function test_detects_migrations_from_archive_contents(): void
    {
        $package = $this->package([
            'VERSION' => "1.0.37\n",
            'backend/database/migrations/2026_09_30_000000_example.php' => '<?php',
        ]);

        try {
            $this->assertTrue((new UpdatePackageInspector)->containsMigrations($package));
        } finally {
            @unlink($package);
        }
    }

    public function test_returns_false_when_archive_has_no_migrations(): void
    {
        $package = $this->package([
            'VERSION' => "1.0.37\n",
            'backend/app/Domain/Updater/UpdateManager.php' => '<?php',
        ]);

        try {
            $this->assertFalse((new UpdatePackageInspector)->containsMigrations($package));
        } finally {
            @unlink($package);
        }
    }

    /**
     * @param array<string, string> $files
     */
    private function package(array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'foodex-update-inspector-');
        if ($path === false) {
            $this->fail('Could not allocate temporary package.');
        }

        $archive = new ZipArchive;
        $this->assertTrue($archive->open($path, ZipArchive::OVERWRITE) === true);

        foreach ($files as $name => $content) {
            $this->assertTrue($archive->addFromString($name, $content));
        }

        $archive->close();

        return $path;
    }
}
