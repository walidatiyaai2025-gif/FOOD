<?php

namespace Tests\Feature;

use Tests\TestCase;

class DashboardSemanticContrastContractTest extends TestCase
{
    public function test_shared_dashboard_semantic_tokens_meet_normal_text_contrast_contract(): void
    {
        $brand = file_get_contents(resource_path('views/admin/_brand.blade.php'));

        $this->assertIsString($brand);

        $action = $this->token($brand, 'foodex-action-green');
        $hover = $this->token($brand, 'foodex-green-dark');
        $dangerText = $this->token($brand, 'foodex-danger-text');
        $dangerSoft = $this->token($brand, 'foodex-danger-soft');

        $this->assertGreaterThanOrEqual(4.5, $this->contrastRatio('#FFFFFF', $action));
        $this->assertGreaterThanOrEqual(4.5, $this->contrastRatio('#FFFFFF', $hover));
        $this->assertGreaterThanOrEqual(4.5, $this->contrastRatio($dangerText, $dangerSoft));
        $this->assertGreaterThanOrEqual(4.5, $this->contrastRatio($dangerText, '#FFFFFF'));
    }

    public function test_shared_action_and_danger_rules_use_accessible_semantic_tokens(): void
    {
        $components = file_get_contents(resource_path('views/admin/_brand-components.blade.php'));

        $this->assertIsString($components);
        $this->assertStringContainsString('background:var(--foodex-action-green)!important;color:#fff!important', $components);
        $this->assertStringContainsString('background:var(--foodex-action-green-hover)!important', $components);
        $this->assertStringContainsString('background:var(--foodex-danger-soft)!important;color:var(--foodex-danger-text)!important', $components);
        $this->assertStringContainsString('.pill.active{background:var(--foodex-action-green)!important;color:#fff!important', $components);
        $this->assertStringContainsString('.foodex-pagination .foodex-pagination-current{background:var(--foodex-action-green);border-color:var(--foodex-action-green);color:#fff', $components);
        $this->assertStringContainsString('.foodex-ops-menu .danger{color:var(--foodex-danger-text)!important', $components);
    }

    private function token(string $css, string $name): string
    {
        $matched = preg_match('/--'.preg_quote($name, '/').'\s*:\s*(#[0-9a-f]{6})\s*;/i', $css, $matches);

        $this->assertSame(1, $matched, 'Missing concrete color token --'.$name);

        return strtoupper($matches[1]);
    }

    private function contrastRatio(string $foreground, string $background): float
    {
        $lighter = max($this->relativeLuminance($foreground), $this->relativeLuminance($background));
        $darker = min($this->relativeLuminance($foreground), $this->relativeLuminance($background));

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

        $linear = array_map(
            static fn (float $channel): float => $channel <= 0.04045
                ? $channel / 12.92
                : (($channel + 0.055) / 1.055) ** 2.4,
            $channels,
        );

        return (0.2126 * $linear[0]) + (0.7152 * $linear[1]) + (0.0722 * $linear[2]);
    }
}
