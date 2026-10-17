<?php

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

class AdminMobileWebAllPagesTest extends TestCase
{
    public function test_every_existing_standalone_admin_page_is_on_the_mobile_web_foundation(): void
    {
        $root = resource_path('views/admin');
        $standalone = [];

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            if (! is_string($content) || ! str_contains(strtolower($content), '<!doctype html>')) {
                continue;
            }

            $standalone[$file->getFilename()] = $content;
        }

        $this->assertGreaterThanOrEqual(36, count($standalone));

        foreach ($standalone as $filename => $content) {
            $this->assertStringContainsString(
                '@include(\'admin._brand-components\')',
                $content,
                $filename.' must inherit the shared Dashboard UI/mobile-web foundation.',
            );
            $this->assertStringContainsString(
                'name="viewport"',
                $content,
                $filename.' must declare a real mobile viewport.',
            );

            if (in_array($filename, ['login.blade.php', 'whatsapp-otp.blade.php'], true)) {
                $this->assertStringContainsString(
                    'data-foodex-mobile-web-exception="auth"',
                    $content,
                    $filename.' must remain inside the unauthenticated auth shell and must not mount the Dashboard drawer.',
                );

                continue;
            }

            $hasCanonicalNavigation = str_contains($content, '@include(\'admin._sidebar')
                || str_contains($content, '@include(\'admin._premium-sidebar');

            $this->assertTrue(
                $hasCanonicalNavigation,
                $filename.' must render the authoritative Dashboard navigation so phone web gets the shared off-canvas drawer.',
            );
        }
    }

    public function test_all_admin_tables_default_to_mobile_record_cards_unless_explicitly_exempted(): void
    {
        $shared = file_get_contents(resource_path('views/admin/_brand-components.blade.php'));

        $this->assertIsString($shared);
        $this->assertStringContainsString(
            'table:not([data-foodex-mobile-table="scroll"]):not([data-foodex-mobile-table="off"])',
            $shared,
        );
        $this->assertStringContainsString(
            'table[data-foodex-mobile-table-ready="1"]{display:block;width:100%;min-width:0!important;',
            $shared,
        );
    }

    public function test_legacy_layout_main_columns_collapse_behind_the_mobile_drawer(): void
    {
        $sidebar = file_get_contents(resource_path('views/admin/_sidebar.blade.php'));

        $this->assertIsString($sidebar);
        $this->assertStringContainsString(
            '.layout>.main,html[dir=ltr] .layout>.main,',
            $sidebar,
        );
        $this->assertStringContainsString(
            'body.foodex-mobile-nav-open',
            $sidebar,
        );
    }
}
