<?php

namespace Tests\Feature;

use Tests\TestCase;

class DashboardContrastTokenContractTest extends TestCase
{
    public function test_shared_action_and_danger_tokens_meet_wcag_aa_for_normal_text(): void
    {
        $brand = file_get_contents(resource_path('views/admin/_brand.blade.php'));

        $this->assertIsString($brand);

        $green = $this->token($brand, '--foodex-green');
        $red = $this->token($brand, '--foodex-red');

        $this->assertGreaterThanOrEqual(4.5, $this->contrastRatio($green, '#FFFFFF'));
        $this->assertGreaterThanOrEqual(4.5, $this->contrastRatio($red, '#FFFFFF'));
        $this->assertGreaterThanOrEqual(4.5, $this->contrastRatio($red, '#FFF0F0'));
    }

    public function test_representative_dashboard_action_surfaces_use_shared_brand_tokens(): void
    {
        $components = file_get_contents(resource_path('views/admin/_brand-components.blade.php'));
        $sidebar = file_get_contents(resource_path('views/admin/_sidebar.blade.php'));
        $notifications = file_get_contents(resource_path('views/admin/notifications.blade.php'));
        $orders = file_get_contents(resource_path('views/admin/order-operations.blade.php'));

        $this->assertIsString($components);
        $this->assertIsString($sidebar);
        $this->assertIsString($notifications);
        $this->assertIsString($orders);

        $this->assertStringContainsString('background:var(--foodex-green)!important;color:#fff!important', $components);
        $this->assertStringContainsString('.pill.active{background:var(--foodex-green)!important;color:#fff!important', $components);
        $this->assertStringContainsString('.foodex-nav-link.active{background:var(--foodex-green);color:#fff', $sidebar);
        $this->assertStringContainsString('.row-actions summary{', $notifications);
        $this->assertStringContainsString('background:var(--foodex-green);color:#fff', $notifications);
        $this->assertStringContainsString('background:var(--foodex-green,#179c52);color:#fff', $orders);
        $this->assertStringContainsString('.danger,.btn.danger{background:#fff0f0!important;color:var(--foodex-red)!important}', $components);
    }

    private function token(string $css, string $name): string
    {
        $matched = preg_match('/'.preg_quote($name, '/').'\s*:\s*(#[0-9A-Fa-f]{6})\s*;/', $css, $matches);

        $this->assertSame(1, $matched, 'Missing color token '.$name);

        return strtoupper($matches[1]);
    }

    private function contrastRatio(string $foreground, string $background): float
    {
        $foregroundLuminance = $this->relativeLuminance($foreground);
        $backgroundLuminance = $this->relativeLuminance($background);
        $lighter = max($foregroundLuminance, $backgroundLuminance);
        $darker = min($foregroundLuminance, $backgroundLuminance);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    private function relativeLuminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $channels = [
            hexdec(substr($hex, 0, 2)) / 255,
            hexdec(substr($hex, 2, 2)) / 255,
            hexdec(substr($hex, 4, 2)) / 255,
        ];

        [$red, $green, $blue] = array_map(
            static fn (float $channel): float => $channel <= 0.04045
                ? $channel / 12.92
                : (($channel + 0.055) / 1.055) ** 2.4,
            $channels,
        );

        return (0.2126 * $red) + (0.7152 * $green) + (0.0722 * $blue);
    }
}
