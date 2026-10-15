<?php

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

class AdminDesignTokenContractTest extends TestCase
{
    public function test_dashboard_foodex_css_references_resolve_to_shared_or_local_tokens(): void
    {
        $sharedSources = [
            resource_path('views/admin/_brand.blade.php'),
            resource_path('views/admin/_brand-components.blade.php'),
            public_path('assets/admin/foodex-visualization.css'),
        ];

        $sharedTokens = [];
        foreach ($sharedSources as $path) {
            $contents = file_get_contents($path);

            $this->assertIsString($contents, 'Unable to read shared design-token source: '.$path);
            foreach ($this->declaredTokens($contents) as $token) {
                $sharedTokens[$token] = true;
            }
        }

        $unresolved = [];
        foreach ($this->dashboardStyleFiles() as $path) {
            $contents = file_get_contents($path);

            $this->assertIsString($contents, 'Unable to read Dashboard style source: '.$path);
            $localTokens = array_fill_keys([
                ...$this->declaredTokens($contents),
                ...$this->dynamicTokens($contents),
            ], true);

            preg_match_all(
                '/var\\(\\s*(--foodex-[a-z0-9-]+)(\\s*,[^)]*)?\\)/i',
                $contents,
                $references,
                PREG_SET_ORDER,
            );

            foreach ($references as $reference) {
                $token = $reference[1];
                $hasFallback = isset($reference[2]) && trim($reference[2]) !== '';

                if (isset($sharedTokens[$token]) || isset($localTokens[$token]) || $hasFallback) {
                    continue;
                }

                $unresolved[] = $this->relativePath($path).' -> '.$token;
            }
        }

        $unresolved = array_values(array_unique($unresolved));
        sort($unresolved);

        $this->assertSame(
            [],
            $unresolved,
            "Dashboard runtime contains unresolved FOODEX CSS custom-property references:\n".implode("\n", $unresolved),
        );
    }

    public function test_retired_dashboard_token_aliases_are_not_used_by_runtime_surfaces(): void
    {
        $retiredAliases = [
            '--foodex-radius-input',
            '--foodex-shadow-md',
            '--foodex-shadow-lg',
            '--foodex-page',
        ];

        $violations = [];
        foreach ($this->dashboardStyleFiles() as $path) {
            $contents = file_get_contents($path);

            $this->assertIsString($contents);
            foreach ($retiredAliases as $alias) {
                if (str_contains($contents, $alias)) {
                    $violations[] = $this->relativePath($path).' -> '.$alias;
                }
            }
        }

        sort($violations);

        $this->assertSame([], $violations, 'Retired FOODEX token aliases remain in Dashboard runtime sources.');
    }

    /**
     * @return list<string>
     */
    private function dashboardStyleFiles(): array
    {
        $files = [];

        foreach ([
            [resource_path('views/admin'), '.blade.php'],
            [public_path('assets/admin'), '.css'],
        ] as [$root, $suffix]) {
            if (! is_dir($root)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
            foreach ($iterator as $entry) {
                if (! $entry instanceof SplFileInfo || ! $entry->isFile()) {
                    continue;
                }

                $path = $entry->getPathname();
                if (str_ends_with($path, $suffix)) {
                    $files[] = $path;
                }
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @return list<string>
     */
    private function declaredTokens(string $contents): array
    {
        preg_match_all('/(?<![a-z0-9_-])(--foodex-[a-z0-9-]+)\\s*:/i', $contents, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    /**
     * @return list<string>
     */
    private function dynamicTokens(string $contents): array
    {
        preg_match_all(
            '/setProperty\\(\\s*[\\'"](--foodex-[a-z0-9-]+)[\\'"]/i',
            $contents,
            $matches,
        );

        return array_values(array_unique($matches[1] ?? []));
    }

    private function relativePath(string $path): string
    {
        $prefix = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($path, $prefix)
            ? str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($prefix)))
            : $path;
    }
}
