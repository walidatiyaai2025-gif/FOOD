<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class EngagementDestinationCatalog
{
    /**
     * This is an engagement allowlist over the production route authorities.
     * Customer/Driver/Van routers remain canonical; do not add a destination
     * here unless the corresponding production route already exists.
     *
     * @var list<array{
     *   code:string,
     *   label:string,
     *   apps:list<string>,
     *   channels:list<string>,
     *   route:string|null,
     *   requires_store:bool
     * }>
     */
    private const DESTINATIONS = [
        [
            'code' => 'none',
            'label' => 'none',
            'apps' => ['all', 'customer', 'driver', 'van'],
            'channels' => ['all', 'b2b', 'b2c'],
            'route' => null,
            'requires_store' => false,
        ],
        [
            'code' => 'customer.marketplace',
            'label' => 'customer_marketplace',
            'apps' => ['customer'],
            'channels' => ['all', 'b2b', 'b2c'],
            'route' => '/marketplace',
            'requires_store' => false,
        ],
        [
            'code' => 'customer.retail_home',
            'label' => 'customer_retail_home',
            'apps' => ['customer'],
            'channels' => ['b2c'],
            'route' => '/retail/{store}/home',
            'requires_store' => true,
        ],
        [
            'code' => 'customer.retail_offers',
            'label' => 'customer_retail_offers',
            'apps' => ['customer'],
            'channels' => ['b2c'],
            'route' => '/offers',
            'requires_store' => false,
        ],
        [
            'code' => 'customer.retail_products',
            'label' => 'customer_retail_products',
            'apps' => ['customer'],
            'channels' => ['b2c'],
            'route' => '/products',
            'requires_store' => false,
        ],
        [
            'code' => 'customer.retail_orders',
            'label' => 'customer_retail_orders',
            'apps' => ['customer'],
            'channels' => ['b2c'],
            'route' => '/orders',
            'requires_store' => false,
        ],
        [
            'code' => 'customer.retail_notifications',
            'label' => 'customer_retail_notifications',
            'apps' => ['customer'],
            'channels' => ['b2c'],
            'route' => '/notifications',
            'requires_store' => false,
        ],
        [
            'code' => 'customer.retail_profile',
            'label' => 'customer_retail_profile',
            'apps' => ['customer'],
            'channels' => ['b2c'],
            'route' => '/profile',
            'requires_store' => false,
        ],
        [
            'code' => 'customer.wholesale_home',
            'label' => 'customer_wholesale_home',
            'apps' => ['customer'],
            'channels' => ['b2b'],
            'route' => '/b2b/home',
            'requires_store' => false,
        ],
        [
            'code' => 'customer.wholesale_dashboard',
            'label' => 'customer_wholesale_dashboard',
            'apps' => ['customer'],
            'channels' => ['b2b'],
            'route' => '/b2b/dashboard',
            'requires_store' => false,
        ],
        [
            'code' => 'customer.wholesale_products',
            'label' => 'customer_wholesale_products',
            'apps' => ['customer'],
            'channels' => ['b2b'],
            'route' => '/b2b/products',
            'requires_store' => false,
        ],
        [
            'code' => 'customer.wholesale_orders',
            'label' => 'customer_wholesale_orders',
            'apps' => ['customer'],
            'channels' => ['b2b'],
            'route' => '/b2b/orders',
            'requires_store' => false,
        ],
        [
            'code' => 'customer.wholesale_notifications',
            'label' => 'customer_wholesale_notifications',
            'apps' => ['customer'],
            'channels' => ['b2b'],
            'route' => '/b2b/notifications',
            'requires_store' => false,
        ],
        [
            'code' => 'customer.wholesale_profile',
            'label' => 'customer_wholesale_profile',
            'apps' => ['customer'],
            'channels' => ['b2b'],
            'route' => '/b2b/profile',
            'requires_store' => false,
        ],
        [
            'code' => 'driver.home',
            'label' => 'driver_home',
            'apps' => ['driver'],
            'channels' => ['b2c'],
            'route' => '/driver/b2c/home',
            'requires_store' => false,
        ],
        [
            'code' => 'driver.deliveries',
            'label' => 'driver_deliveries',
            'apps' => ['driver'],
            'channels' => ['b2c'],
            'route' => '/driver/b2c/deliveries',
            'requires_store' => false,
        ],
        [
            'code' => 'driver.notifications',
            'label' => 'driver_notifications',
            'apps' => ['driver'],
            'channels' => ['b2c'],
            'route' => '/driver/b2c/notifications',
            'requires_store' => false,
        ],
        [
            'code' => 'driver.wallet',
            'label' => 'driver_wallet',
            'apps' => ['driver'],
            'channels' => ['b2c'],
            'route' => '/driver/b2c/wallet',
            'requires_store' => false,
        ],
    ];

    /** @return list<array{code:string,label:string,apps:list<string>,channels:list<string>,requires_store:bool}> */
    public function uiDefinitions(): array
    {
        return array_map(
            static fn (array $destination): array => [
                'code' => $destination['code'],
                'label' => __('engagement_destinations.options.'.$destination['label']),
                'apps' => $destination['apps'],
                'channels' => $destination['channels'],
                'requires_store' => $destination['requires_store'],
            ],
            self::DESTINATIONS,
        );
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_column(self::DESTINATIONS, 'code');
    }

    public function resolve(
        ?string $code,
        string $app,
        string $channel,
        ?int $storeId = null,
        string $field = 'cta_destination',
    ): ?string {
        $normalized = trim((string) $code);
        if ($normalized === '') {
            $normalized = 'none';
        }

        $destination = $this->definition($normalized);
        if ($destination === null || ! $this->compatible($destination, $app, $channel)) {
            throw ValidationException::withMessages([
                $field => [__('engagement_destinations.invalid')],
            ]);
        }

        if ($destination['route'] === null) {
            return null;
        }

        if ($destination['requires_store']) {
            if ($storeId === null || $storeId <= 0) {
                throw ValidationException::withMessages([
                    $field => [__('engagement_destinations.store_required')],
                ]);
            }

            return str_replace('{store}', (string) $storeId, $destination['route']);
        }

        return $destination['route'];
    }

    public function resolveInput(
        ?string $code,
        mixed $legacyTarget,
        string $app,
        string $channel,
        ?int $storeId = null,
        string $field = 'cta_destination',
    ): ?string {
        if ($code !== null && trim($code) !== '') {
            return $this->resolve($code, $app, $channel, $storeId, $field);
        }

        if (! is_string($legacyTarget) || trim($legacyTarget) === '') {
            return null;
        }

        $target = trim($legacyTarget);
        if ($this->codeForTarget($target, $app, $channel, $storeId) === null) {
            throw ValidationException::withMessages([
                $field => [__('engagement_destinations.invalid')],
            ]);
        }

        return $target;
    }

    public function codeForTarget(
        mixed $target,
        string $app,
        string $channel,
        ?int $storeId = null,
    ): string {
        if (! is_string($target) || trim($target) === '') {
            return 'none';
        }

        $target = trim($target);
        foreach (self::DESTINATIONS as $destination) {
            if ($destination['route'] === null || ! $this->compatible($destination, $app, $channel)) {
                continue;
            }

            if ($destination['requires_store'] && ($storeId === null || $storeId <= 0)) {
                continue;
            }

            $resolved = $destination['requires_store']
                ? str_replace('{store}', (string) $storeId, $destination['route'])
                : $destination['route'];

            if ($resolved === $target) {
                return $destination['code'];
            }
        }

        return '';
    }

    /** @return array{code:string,label:string,apps:list<string>,channels:list<string>,route:string|null,requires_store:bool}|null */
    private function definition(string $code): ?array
    {
        foreach (self::DESTINATIONS as $destination) {
            if ($destination['code'] === $code) {
                return $destination;
            }
        }

        return null;
    }

    /** @param array{apps:list<string>,channels:list<string>} $destination */
    private function compatible(array $destination, string $app, string $channel): bool
    {
        return in_array($app, $destination['apps'], true)
            && in_array($channel, $destination['channels'], true);
    }
}
