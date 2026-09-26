<?php

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

class TypographyContractTest extends TestCase
{
    public function test_runtime_views_use_single_canonical_tajawal_family(): void
    {
        $brand = file_get_contents(resource_path('views/admin/_brand.blade.php'));

        $this->assertIsString($brand);
        $this->assertStringContainsString('fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700', $brand);
        $this->assertStringContainsString('--foodex-font-family:"Tajawal",sans-serif', $brand);
        $this->assertStringContainsString('--foodex-font-ar:var(--foodex-font-family)', $brand);
        $this->assertStringContainsString('--foodex-font-en:var(--foodex-font-family)', $brand);
        $this->assertStringContainsString('--foodex-font-ui:var(--foodex-font-family)', $brand);

        $views = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views')));

        foreach ($views as $view) {
            if (! $view->isFile() || ! str_ends_with($view->getFilename(), '.blade.php')) {
                continue;
            }

            $contents = file_get_contents($view->getPathname());
            $this->assertIsString($contents);
            $this->assertDoesNotMatchRegularExpression(
                '/font-family\s*:[^;]*(?:Inter|Tahoma|Arial|system-ui|ui-sans-serif|-apple-system)/i',
                $contents,
                'Legacy UI font declaration found in '.$view->getPathname(),
            );
        }
    }
}
