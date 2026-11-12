<?php

namespace App\Console\Commands;

use App\Services\AdminSidebarManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use JsonException;

final class V2NavigationSnapshot extends Command
{
    protected $signature = 'foodex:v2-navigation-snapshot {--against= : Compare with an authorized, independently captured JSON snapshot}';

    protected $description = 'Emit or compare redacted V2 Sidebar code/runtime inventories without changing permissions or claiming production attestation';

    public function handle(AdminSidebarManager $sidebar): int
    {
        $registry = $sidebar->registry();
        $canonical = $this->canonical($registry);
        $tablesReady = Schema::hasTable('admin_sidebar_groups')
            && Schema::hasTable('admin_sidebar_items')
            && Schema::hasTable('admin_sidebar_role_visibility');
        $savedLayout = $tablesReady ? $this->savedLayout($sidebar->layoutData()) : null;

        $snapshot = [
            'schema_version' => 1,
            'issue' => 2777,
            'source' => 'P026 / current application registry and local database only',
            'read_only' => true,
            'production_deployment_verified' => false,
            'environment_identity' => 'not_attested',
            'sidebar_tables_present' => $tablesReady,
            'route_authorization' => 'not_tested',
            'role_tenant_module_authorization' => 'not_tested',
            'canonical' => $canonical,
            'saved_layout' => $savedLayout,
            'canonical_sha256' => $this->fingerprint($canonical),
            'saved_layout_sha256' => $savedLayout === null ? null : $this->fingerprint($savedLayout),
        ];

        $result = self::SUCCESS;
        $comparison = ['status' => 'not_compared', 'production_verified' => false];
        $against = $this->option('against');

        if (is_string($against) && $against !== '') {
            $baseline = $this->loadBaseline($against);
            if ($baseline === null) {
                $comparison = ['status' => 'invalid_baseline', 'production_verified' => false];
                $result = self::FAILURE;
            } elseif ($savedLayout === null || $baseline['saved_layout'] === null) {
                $comparison = ['status' => 'incomplete_runtime_inventory', 'production_verified' => false];
                $result = self::FAILURE;
            } else {
                $diff = [
                    'canonical_groups' => $this->difference($baseline['canonical']['groups'], $canonical['groups']),
                    'canonical_items' => $this->difference($baseline['canonical']['items'], $canonical['items']),
                    'saved_groups' => $this->difference($baseline['saved_layout']['groups'], $savedLayout['groups']),
                    'saved_items' => $this->difference($baseline['saved_layout']['items'], $savedLayout['items']),
                ];

                $drift = collect($diff)->contains(
                    static fn (array $counts): bool => array_sum($counts) > 0
                );
                $comparison = [
                    'status' => $drift ? 'drift_detected' : 'matching_snapshots_unattested',
                    'production_verified' => false,
                    'differences' => $diff,
                ];
                if ($drift) {
                    $result = self::FAILURE;
                }
            }
        }

        $snapshot['comparison'] = $comparison;
        $this->line((string) json_encode(
            $snapshot,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        return $result;
    }

    /**
     * Only schema metadata is allowed in the export. Never include account rows,
     * credentials, custom label text, per-role visibility or environment values.
     *
     * @param  array<string,mixed>  $registry
     * @return array{groups:array<string,array<string,mixed>>,items:array<string,array<string,mixed>>}
     */
    private function canonical(array $registry): array
    {
        $groups = [];
        foreach ((array) ($registry['groups'] ?? []) as $key => $definition) {
            $definition = (array) $definition;
            $groups[(string) $key] = [
                'label_key' => (string) ($definition['label'] ?? ''),
                'sort_order' => (int) ($definition['order'] ?? 0),
            ];
        }
        ksort($groups);

        $items = [];
        foreach ((array) ($registry['items'] ?? []) as $key => $definition) {
            $definition = (array) $definition;
            $permissions = array_values(array_filter(
                (array) ($definition['permissions'] ?? []),
                'is_string',
            ));
            sort($permissions);
            $items[(string) $key] = [
                'group_key' => (string) ($definition['group'] ?? ''),
                'scope' => (string) ($definition['scope'] ?? 'both'),
                'channel' => is_string($definition['channel'] ?? null) ? $definition['channel'] : null,
                'module' => is_string($definition['module'] ?? null) ? $definition['module'] : null,
                'permissions' => $permissions,
                'permission_mode' => (string) ($definition['mode'] ?? 'all'),
            ];
        }
        ksort($items);

        return ['groups' => $groups, 'items' => $items];
    }

    /**
     * @param  array<string,mixed>  $layout
     * @return array{groups:array<string,array<string,mixed>>,items:array<string,array<string,mixed>>}
     */
    private function savedLayout(array $layout): array
    {
        $groups = [];
        foreach ((array) ($layout['groups'] ?? []) as $key => $group) {
            $group = (array) $group;
            $groups[(string) $key] = [
                'enabled' => (bool) ($group['is_enabled'] ?? true),
                'sort_order' => (int) ($group['sort_order'] ?? 0),
                'configured' => (bool) ($group['configured'] ?? false),
            ];
        }
        ksort($groups);

        $items = [];
        foreach ((array) ($layout['items'] ?? []) as $key => $item) {
            $item = (array) $item;
            $items[(string) $key] = [
                'group_key' => (string) ($item['group_key'] ?? ''),
                'parent_key' => is_string($item['parent_key'] ?? null) ? $item['parent_key'] : null,
                'enabled' => (bool) ($item['is_enabled'] ?? true),
                'sort_order' => (int) ($item['sort_order'] ?? 0),
                'configured' => (bool) ($item['configured'] ?? false),
            ];
        }
        ksort($items);

        return ['groups' => $groups, 'items' => $items];
    }

    /** @param array<string,mixed> $value */
    private function fingerprint(array $value): string
    {
        return hash('sha256', (string) json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array{canonical:array<string,mixed>,saved_layout:array<string,mixed>|null}|null
     */
    private function loadBaseline(string $path): ?array
    {
        if (is_link($path) || ! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $size = filesize($path);
        if ($size === false || $size > 1048576) {
            return null;
        }

        $json = file_get_contents($path);
        if (! is_string($json)) {
            return null;
        }

        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded) || ($decoded['schema_version'] ?? null) !== 1
            || ($decoded['issue'] ?? null) !== 2777
            || ! $this->validInventory($decoded['canonical'] ?? null)
            || ($decoded['saved_layout'] ?? null) !== null
                && ! $this->validInventory($decoded['saved_layout'])) {
            return null;
        }

        return [
            'canonical' => $decoded['canonical'],
            'saved_layout' => $decoded['saved_layout'] ?? null,
        ];
    }

    private function validInventory(mixed $inventory): bool
    {
        if (! is_array($inventory) || ! is_array($inventory['groups'] ?? null)
            || ! is_array($inventory['items'] ?? null)) {
            return false;
        }

        foreach (['groups', 'items'] as $type) {
            foreach ($inventory[$type] as $key => $definition) {
                if (! is_string($key) || ! preg_match('/^[a-z0-9_:-]{1,100}$/D', $key)
                    || ! is_array($definition)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Count changed registered/persisted keys, but never echo baseline values.
     *
     * @param  array<string,mixed>  $previous
     * @param  array<string,mixed>  $current
     * @return array{removed:int,added:int,changed:int}
     */
    private function difference(array $previous, array $current): array
    {
        return [
            'removed' => count(array_diff_key($previous, $current)),
            'added' => count(array_diff_key($current, $previous)),
            'changed' => count(array_filter(
                array_intersect_key($current, $previous),
                static fn (mixed $value, string $key): bool => $value !== $previous[$key],
                ARRAY_FILTER_USE_BOTH,
            )),
        ];
    }
}
