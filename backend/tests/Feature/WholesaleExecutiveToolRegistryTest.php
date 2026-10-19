<?php

namespace Tests\Feature;

use App\Domain\Assistant\Tools\AssistantToolRegistry;
use Tests\TestCase;

final class WholesaleExecutiveToolRegistryTest extends TestCase
{
    public function test_wholesale_executive_tool_is_registered_as_b2b_read_only_governed_capability(): void
    {
        $registry = app(AssistantToolRegistry::class);

        $this->assertTrue($registry->has('executive.wholesale'));

        $definition = $registry->definition('executive.wholesale');
        $this->assertSame('READ', $definition->mode);
        $this->assertSame(['b2b'], $definition->channels);
        $this->assertSame(
            ['reports.view', 'catalog.view', 'b2b.accounts.view'],
            $definition->requiredPermissions,
        );
        $this->assertSame('all', $definition->permissionMode);
        $this->assertSame('WholesaleExecutiveIntelligenceService', $definition->source);
        $this->assertFalse($definition->sellOutConsent);
        $this->assertSame(5000, $definition->timeoutMs);
        $this->assertFalse($definition->inputSchema['additionalProperties']);
        $this->assertSame(0, $definition->inputSchema['properties']['target_revenue']['minimum']);

        $providerTool = collect($registry->providerTools('ar'))
            ->firstWhere('x-foodex.tool_key', 'executive.wholesale');

        $this->assertIsArray($providerTool);
        $this->assertSame('executive__wholesale', $providerTool['function']['name']);
        $this->assertStringContainsString('الجملة', $providerTool['function']['description']);
        $this->assertSame('executive.wholesale', $providerTool['x-foodex']['tool_key']);
    }
}
