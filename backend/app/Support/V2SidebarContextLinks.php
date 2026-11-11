<?php

namespace App\Support;

use Illuminate\Support\Facades\Route;

/**
 * P160 context choices are only shortcuts to the final authorized Sidebar.
 * This is a presentation projection, never an independent permission grant.
 */
final class V2SidebarContextLinks
{
    /** @var array<string, list<string>> */
    private const ENTRY_KEYS = [
        // Enterprise Workspaces may be store-scoped; it is not proof of platform context.
        'platform' => ['administration_hub'],
        'wholesale' => ['b2b_dashboard'],
        'retail' => ['b2c_dashboard'],
        'restaurant' => ['smart_ordering'],
        'analytics' => ['reports_center', 'b2c_reports', 'b2b_reports', 'erp_bi'],
    ];

    /**
     * @param  list<array<string, mixed>>  $authorizedGroups
     * @return list<array{context:string,route:string,params:array<string,mixed>}>
     */
    public function fromAuthorizedGroups(array $authorizedGroups): array
    {
        $authorizedItems = [];

        $collect = static function (array $children) use (&$collect, &$authorizedItems): void {
            foreach ($children as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $key = $item['key'] ?? null;
                $route = $item['route'] ?? null;
                $params = $item['params'] ?? [];

                if (is_string($key) && is_string($route) && is_array($params)
                    && Route::has($route) && ! isset($authorizedItems[$key])) {
                    $authorizedItems[$key] = ['route' => $route, 'params' => $params];
                }

                $collect((array) ($item['children'] ?? []));
            }
        };

        foreach ($authorizedGroups as $group) {
            $collect((array) ($group['children'] ?? []));
        }

        $links = [];

        foreach (self::ENTRY_KEYS as $context => $candidates) {
            foreach ($candidates as $key) {
                if (! isset($authorizedItems[$key])) {
                    continue;
                }

                $links[] = [
                    'context' => $context,
                    'route' => $authorizedItems[$key]['route'],
                    'params' => $authorizedItems[$key]['params'],
                ];
                break;
            }
        }

        return $links;
    }
}
