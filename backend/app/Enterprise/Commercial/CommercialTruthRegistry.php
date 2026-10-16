<?php

namespace App\Enterprise\Commercial;

use App\Domain\Pricing\B2bPriceResolver;
use App\Models\Order;
use App\Services\B2bAccountLedgerService;
use App\Services\CollectionCustodyService;
use App\Services\CommerceQuoteService;
use App\Services\CustomerDomainResolver;
use InvalidArgumentException;

final class CommercialTruthRegistry
{
    public const CONTRACT_VERSION = 1;

    /**
     * @return array<string, array{
     *     channels:list<string>,
     *     read_authority:class-string,
     *     write_authority:class-string,
     *     supporting_authorities:list<class-string>,
     *     storage:list<string>,
     *     compatibility:string,
     *     event:string
     * }>
     */
    public function all(): array
    {
        return [
            'customer' => [
                'channels' => ['b2b', 'b2c'],
                'read_authority' => CustomerDomainResolver::class,
                'write_authority' => CustomerDomainResolver::class,
                'supporting_authorities' => [],
                'storage' => ['b2b_customers', 'b2c_customers', 'customers'],
                'compatibility' => 'CustomerDomainResolver owns channel-aware identity; customers is legacy compatibility only.',
                'event' => 'foodex.commercial.customer.changed',
            ],
            'order' => [
                'channels' => ['b2b', 'b2c'],
                'read_authority' => Order::class,
                'write_authority' => Order::class,
                'supporting_authorities' => [],
                'storage' => ['orders', 'order_items', 'order_status_history'],
                'compatibility' => 'Existing orders and immutable commercial snapshots remain authoritative.',
                'event' => 'foodex.commercial.order.changed',
            ],
            'pricing' => [
                'channels' => ['b2b', 'b2c'],
                'read_authority' => CommerceQuoteService::class,
                'write_authority' => CommerceQuoteService::class,
                'supporting_authorities' => [B2bPriceResolver::class],
                'storage' => ['store_products', 'b2b_price_tiers', 'b2b_price_tier_items', 'promotions', 'coupons'],
                'compatibility' => 'Consumers reuse backend-authoritative quote and pricing resolution; submitted prices are never authority.',
                'event' => 'foodex.commercial.pricing.changed',
            ],
            'credit' => [
                'channels' => ['b2b'],
                'read_authority' => B2bAccountLedgerService::class,
                'write_authority' => B2bAccountLedgerService::class,
                'supporting_authorities' => [CommercialCreditEligibilityPolicy::class],
                'storage' => ['b2b_accounts', 'customer_account_ledger_entries'],
                'compatibility' => 'Credit limit, signed balance and purchasing power reuse the existing B2B account ledger contract.',
                'event' => 'foodex.commercial.credit.changed',
            ],
            'collection' => [
                'channels' => ['b2b'],
                'read_authority' => CollectionCustodyService::class,
                'write_authority' => CollectionCustodyService::class,
                'supporting_authorities' => [],
                'storage' => ['collection_transactions', 'collection_allocations', 'custody_ledger_entries', 'remittances'],
                'compatibility' => 'Collection and custody provenance reuse the existing collection/remittance domain without a second payment ledger.',
                'event' => 'foodex.commercial.collection.changed',
            ],
        ];
    }

    /**
     * @return array{
     *     channels:list<string>,
     *     read_authority:class-string,
     *     write_authority:class-string,
     *     supporting_authorities:list<class-string>,
     *     storage:list<string>,
     *     compatibility:string,
     *     event:string
     * }
     */
    public function authority(string $fact, string $channel): array
    {
        $fact = strtolower(trim($fact));
        $channel = strtolower(trim($channel));
        $authority = $this->all()[$fact] ?? null;

        if ($authority === null) {
            throw new InvalidArgumentException("Unknown commercial fact [{$fact}].");
        }

        if (! in_array($channel, $authority['channels'], true)) {
            throw new InvalidArgumentException("Commercial fact [{$fact}] is not available for channel [{$channel}].");
        }

        return $authority;
    }
}
