<?php

namespace Tests\Feature;

use Tests\TestCase;

class OpenApiContractTest extends TestCase
{
    private string $contract;

    protected function setUp(): void
    {
        parent::setUp();

        $path = base_path('../docs/api/openapi.yaml');
        $contents = file_get_contents($path);

        $this->assertNotFalse($contents, 'OpenAPI contract could not be read.');
        $this->contract = $contents;
    }

    public function test_required_v1_domains_have_contract_paths(): void
    {
        $paths = [
            '/auth/login',
            '/auth/logout',
            '/profile',
            '/stores',
            '/stores/{store}/categories',
            '/stores/{store}/products',
            '/stores/{store}/offers',
            '/products/{product}',
            '/cart',
            '/cart/items',
            '/checkout',
            '/orders',
            '/orders/{order}',
            '/b2b/dashboard',
            '/b2b/products',
            '/b2b/orders',
            '/b2b/invoices',
            '/b2b/account-statement',
            '/driver/assignments',
            '/driver/assignments/{assignment}/status',
            '/notifications',
            '/app-version',
            '/admin/security/permissions',
            '/admin/security/roles',
            '/admin/security/users',
            '/admin/security/users/{user}/roles',
            '/admin/security/users/{user}/status',
        ];

        foreach ($paths as $path) {
            $this->assertStringContainsString("\n  {$path}:", $this->contract, "Missing OpenAPI path: {$path}");
        }
    }

    public function test_required_shared_schemas_are_declared(): void
    {
        $schemas = [
            'LoginRequest',
            'LoginResponse',
            'UserIdentity',
            'Store',
            'Category',
            'Product',
            'Cart',
            'CheckoutRequest',
            'Order',
            'B2bProduct',
            'Invoice',
            'B2bAccountStatement',
            'DriverAssignment',
            'Notification',
            'AppVersionPolicy',
            'SecurityPermission',
            'SecurityRole',
            'SecurityUser',
            'SecurityUserRolesWrite',
            'SecurityUserStatusWrite',
            'PaginationMeta',
            'Error',
        ];

        foreach ($schemas as $schema) {
            $this->assertMatchesRegularExpression(
                "/^    {$schema}:$/m",
                $this->contract,
                "Missing OpenAPI schema: {$schema}",
            );
        }
    }

    public function test_checkout_is_authenticated_and_idempotent(): void
    {
        $checkout = $this->pathBlock('/checkout', '/orders');

        $this->assertStringContainsString('bearerAuth: []', $checkout);
        $this->assertStringContainsString('#/components/parameters/IdempotencyKey', $checkout);
        $this->assertStringContainsString('#/components/schemas/CheckoutRequest', $checkout);
    }

    public function test_b2b_contract_has_no_public_self_registration_path(): void
    {
        $this->assertStringNotContainsString('/b2b/register:', $this->contract);
        $this->assertStringNotContainsString('/b2b/signup:', $this->contract);
    }

    public function test_protected_domain_paths_require_bearer_auth(): void
    {
        $protected = [
            ['/orders', '/orders/{order}'],
            ['/b2b/dashboard', '/b2b/products'],
            ['/b2b/products', '/b2b/orders'],
            ['/driver/assignments', '/driver/assignments/{assignment}/status'],
            ['/driver/assignments/{assignment}/status', '/notifications'],
            ['/notifications', '/notifications/{notification}/read'],
        ];

        foreach ($protected as [$path, $nextPath]) {
            $this->assertStringContainsString(
                'bearerAuth: []',
                $this->pathBlock($path, $nextPath),
                "Protected path {$path} must require bearer authentication.",
            );
        }
    }

    private function pathBlock(string $path, string $nextPath): string
    {
        $start = strpos($this->contract, "\n  {$path}:");
        $end = strpos($this->contract, "\n  {$nextPath}:", $start + 1);

        $this->assertNotFalse($start, "Missing OpenAPI path: {$path}");
        $this->assertNotFalse($end, "Missing next OpenAPI path boundary: {$nextPath}");

        return substr($this->contract, $start, $end - $start);
    }
}
