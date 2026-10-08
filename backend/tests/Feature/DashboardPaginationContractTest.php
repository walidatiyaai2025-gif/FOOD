<?php

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

class DashboardPaginationContractTest extends TestCase
{
    /**
     * Tables that are intrinsically bounded detail/summary surfaces rather than
     * record-list grids. Every exception needs a stable reason so that a new
     * unpaginated Dashboard table cannot silently bypass the contract.
     *
     * @var array<string,string>
     */
    private array $boundedExceptions = [
        'app-versions.blade.php' => 'fixed app/platform policy matrix',
        'invoice.blade.php' => 'single-invoice line-item detail',
        'flash-offer-preview.blade.php' => 'single-offer product preview detail',
        'flash-offer-analytics.blade.php' => 'single-offer grouped analytics summaries',
    ];

    public function test_every_admin_table_has_a_pagination_contract(): void
    {
        $root = resource_path('views/admin');
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        $violations = [];

        foreach ($iterator as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            if (! is_string($content) || ! preg_match('/<table\b/i', $content)) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $hasExplicitContract = str_contains($content, 'data-pagination-required')
                || str_contains($content, 'data-pagination-exempt=');
            $hasLaravelPaginator = str_contains($content, '->links()');
            $hasCustomPaginator = str_contains($content, "['current_page']")
                && str_contains($content, "['last_page']");
            $isBoundedException = array_key_exists($relative, $this->boundedExceptions);

            if (! $hasExplicitContract && ! $hasLaravelPaginator && ! $hasCustomPaginator && ! $isBoundedException) {
                $violations[] = $relative;
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Dashboard tables without pagination contract:\n - ".implode("\n - ", $violations),
        );
    }

    public function test_geographic_engineering_uses_independent_server_paginators(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Admin/FieldOperationsController.php'));
        $view = file_get_contents(resource_path('views/admin/field-operations.blade.php'));

        $this->assertIsString($controller);
        $this->assertIsString($view);
        $this->assertStringContainsString("->paginate(25, ['*'], 'geography_page')", $controller);
        $this->assertStringContainsString("->paginate(25, ['*'], 'territory_page')", $controller);
        $this->assertStringContainsString('$nodeRows->links()', $view);
        $this->assertStringContainsString('$territoryRows->links()', $view);
    }

    public function test_high_growth_dashboard_histories_are_server_paginated(): void
    {
        $catalog = file_get_contents(app_path('Http/Controllers/Admin/CatalogManagementController.php'));
        $customer = file_get_contents(app_path('Http/Controllers/Admin/Customer360Controller.php'));
        $updates = file_get_contents(app_path('Http/Controllers/Admin/SystemUpdateController.php'));
        $commercial = file_get_contents(app_path('Http/Controllers/Admin/CommercialDashboardController.php'));

        $this->assertStringContainsString("'product_page'", (string) $catalog);
        $this->assertStringContainsString("'category_page'", (string) $catalog);
        $this->assertStringContainsString("'orders_page'", (string) $customer);
        $this->assertStringContainsString("'invoices_page'", (string) $customer);
        $this->assertStringContainsString('->paginate(20)->withQueryString()', (string) $updates);
        $this->assertStringContainsString("'promotion_page'", (string) $commercial);
        $this->assertStringContainsString("'offer_page'", (string) $commercial);
    }
}
