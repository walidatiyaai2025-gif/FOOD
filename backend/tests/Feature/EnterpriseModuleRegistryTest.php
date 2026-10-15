<?php

namespace Tests\Feature;

use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleManifestCatalog;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

final class EnterpriseModuleRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_manifest_catalog_is_config_only_and_performs_no_database_queries(): void
    {
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $manifest = app(ModuleManifestCatalog::class)->require('sample_optional');

        $this->assertSame('sample_optional', $manifest->code);
        $this->assertSame(ModuleState::DISABLED, $manifest->defaultState);
        $this->assertSame([], $manifest->capabilities);
        $this->assertSame([], $queries);
    }

    public function test_manifest_sync_is_additive_and_never_resets_runtime_state(): void
    {
        $registry = app(ModuleRegistry::class);

        $module = $registry->syncManifest('sample_optional');
        $this->assertSame(ModuleState::DISABLED, $module->state);

        $module->forceFill(['state' => ModuleState::ERROR])->save();

        config()->set('enterprise_modules.modules.sample_optional.version', 2);
        $resynced = $registry->syncManifest('sample_optional');

        $this->assertSame(2, $resynced->manifest_version);
        $this->assertSame(ModuleState::ERROR, $resynced->state);
    }

    public function test_lifecycle_enforces_state_machine_and_records_audit(): void
    {
        $manager = app(ModuleLifecycleManager::class);

        $manager->transition('sample_optional', ModuleState::ENABLING);
        $enabled = $manager->transition('sample_optional', ModuleState::ENABLED);

        $this->assertSame(ModuleState::ENABLED, $enabled->state);
        $this->assertNotNull($enabled->enabled_at);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.module.state_changed',
            'auditable_id' => $enabled->id,
        ]);

        $this->expectException(LogicException::class);
        $manager->transition('sample_optional', ModuleState::INSTALLED);
    }

    public function test_hard_dependency_cannot_be_disabled_while_active_dependent_requires_it(): void
    {
        config()->set('enterprise_modules.modules.catalog', [
            'version' => 1,
            'category' => 'platform',
            'default_state' => 'DISABLED',
            'required_core' => ['module_registry'],
            'hard_dependencies' => [],
            'optional_integrations' => [],
            'capabilities' => ['catalog.core'],
            'permissions' => [],
            'health_checks' => [],
            'navigation' => [],
        ]);
        config()->set('enterprise_modules.modules.procurement', [
            'version' => 1,
            'category' => 'operations',
            'default_state' => 'DISABLED',
            'required_core' => ['module_registry'],
            'hard_dependencies' => ['catalog'],
            'optional_integrations' => ['accounting'],
            'capabilities' => ['procurement.core'],
            'permissions' => ['procurement.view'],
            'health_checks' => ['database'],
            'navigation' => ['group' => 'operations', 'order' => 40],
        ]);

        $manager = app(ModuleLifecycleManager::class);
        $manager->transition('catalog', ModuleState::ENABLING);
        $manager->transition('catalog', ModuleState::ENABLED);
        $manager->transition('procurement', ModuleState::ENABLING);
        $manager->transition('procurement', ModuleState::ENABLED);

        $this->expectException(LogicException::class);
        $manager->transition('catalog', ModuleState::DISABLED);
    }

    public function test_disabled_module_requires_no_provider_credentials_and_preserves_registration(): void
    {
        $registry = app(ModuleRegistry::class);
        $module = $registry->syncManifest('sample_optional');

        $this->assertSame(ModuleState::DISABLED, $module->state);
        $this->assertFalse($registry->isEnabled('sample_optional'));
        $this->assertDatabaseHas('enterprise_modules', [
            'code' => 'sample_optional',
            'state' => ModuleState::DISABLED->value,
        ]);
    }

    public function test_registry_migration_can_be_rolled_back_and_reapplied(): void
    {
        $migration = require database_path(
            'migrations/2026_10_15_124500_create_enterprise_module_registry.php',
        );

        $migration->down();
        $this->assertFalse(Schema::hasTable('enterprise_modules'));

        $migration->up();
        $this->assertTrue(Schema::hasColumns('enterprise_modules', [
            'code',
            'manifest_version',
            'state',
            'manifest',
            'state_changed_at',
        ]));
    }
}
