<?php

namespace App\Enterprise\Commercial;

use App\Domain\WhatsApp\RuntimePolicy;
use App\Models\B2bCustomer;
use App\Models\B2cCustomer;
use App\Models\User;
use App\Repositories\B2bCustomerRepository;
use App\Repositories\B2cCustomerRepository;
use App\Services\B2bAccountLedgerService;
use App\Services\CustomerDomainResolver;
use App\Services\OperationalTenantScope;
use App\Services\PlatformCustomerService;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Read-only composition; source services retain all identity and ledger authority. */
final class CommercialCustomer360Service
{
    public function __construct(
        private readonly CommercialContextGuard $context,
        private readonly OperationalTenantScope $scope,
        private readonly CustomerDomainResolver $customers,
        private readonly B2cCustomerRepository $retailCustomers,
        private readonly B2bAccountLedgerService $ledger,
        private readonly PlatformCustomerService $platformCustomers,
        private readonly RuntimePolicy $whatsapp,
    ) {}

    /** @return array<string, mixed> */
    public function snapshot(
        User $actor,
        int $storeId,
        string $channel,
        int $customerUserId,
        int $limit = 25,
        ?int $beforeOrderId = null,
    ): array {
        $channel = strtolower(trim($channel));
        $this->context->assert($actor, $storeId, $channel, $channel === 'b2b' ? 'b2b.accounts.view' : 'customers.view');
        $user = User::query()->findOrFail($customerUserId);
        // Never call materializing resolver methods from a read composition.
        $customer = $channel === 'b2b'
            ? $this->customers->existingB2b($user)
            : $this->retailCustomers->forUserAndStore($user, $storeId);
        abort_unless($customer instanceof B2bCustomer || $customer instanceof B2cCustomer, 404);

        return $this->compose($actor, $storeId, $channel, $customer, $user, $limit, $beforeOrderId);
    }

    /** Resolve an existing domain record, including customer records without login users.
     * @return array<string, mixed>
     */
    public function forDomain(
        User $actor,
        int $storeId,
        string $channel,
        int $customerId,
        int $limit = 25,
        ?int $beforeOrderId = null,
    ): array {
        $channel = strtolower(trim($channel));
        $this->context->assert($actor, $storeId, $channel, $channel === 'b2b' ? 'b2b.accounts.view' : 'customers.view');
        $customer = $channel === 'b2b'
            ? app(B2bCustomerRepository::class)->find($customerId)
            : $this->retailCustomers->findForStore($customerId, $storeId);
        abort_unless($customer instanceof B2bCustomer || $customer instanceof B2cCustomer, 404);
        $user = $customer->user;

        return $this->compose($actor, $storeId, $channel, $customer, $user instanceof User ? $user : null, $limit, $beforeOrderId);
    }

    /** @return array<string, mixed> */
    private function compose(
        User $actor,
        int $storeId,
        string $channel,
        B2bCustomer|B2cCustomer $customer,
        ?User $user,
        int $limit,
        ?int $beforeOrderId,
    ): array {
        $customerColumn = $channel === 'b2b' ? 'b2b_customer_id' : 'b2c_customer_id';
        $customerId = (int) $customer->getKey();
        if ($channel === 'b2b') {
            // Wholesale identity is global; composing it needs a canonical store relationship.
            $related = DB::table('orders')->where('store_id', $storeId)->where($customerColumn, $customerId)->exists()
                || DB::table('invoices')->where('store_id', $storeId)->where($customerColumn, $customerId)->exists()
                || DB::table('retail_wholesale_accounts')->where('b2b_customer_id', $customerId)->where('source_wholesale_store_id', $storeId)->exists();
            abort_unless($related, 404);
        }
        $limit = max(1, min(100, $limit));
        $asOf = now()->toIso8601String();
        $orders = ['state' => 'forbidden', 'items' => [], 'next_before_id' => null];
        if (in_array($storeId, $this->scope->allowedStoreIds($actor, 'orders.view', $channel), true)) {
            $rows = DB::table('orders')
                ->where('store_id', $storeId)
                ->where('channel', $channel)
                ->where($customerColumn, $customerId)
                ->when($beforeOrderId !== null, fn ($query) => $query->where('id', '<', $beforeOrderId))
                ->orderByDesc('id')
                ->limit($limit + 1)
                ->get(['id', 'order_number', 'status', 'currency', 'grand_total', 'created_at']);
            $items = $rows->take($limit)->map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'number' => (string) $row->order_number,
                'status' => (string) $row->status,
                'currency' => (string) $row->currency,
                'total' => (float) $row->grand_total,
                'created_at' => $row->created_at,
                'url' => route('admin.operations.orders.index', ['store_id' => $storeId, 'channel' => $channel, 'order' => $row->id]),
            ])->values()->all();
            $orders = [
                'state' => 'current_read',
                'items' => $items,
                'next_before_id' => $rows->count() > $limit ? $items[count($items) - 1]['id'] : null,
            ];
        }

        return [
            'contract_version' => 1,
            'as_of' => $asOf,
            'state' => 'current_read',
            'store_id' => $storeId,
            'channel' => $channel,
            'identity' => [
                'authority' => CustomerDomainResolver::class,
                'domain_type' => $channel,
                'domain_id' => $customerId,
                'user_id' => $user === null ? null : (int) $user->getKey(),
                'legacy_customer_id' => $customer->legacy_customer_id === null ? null : (int) $customer->legacy_customer_id,
            ],
            'profile' => [
                'name' => (string) $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'updated_at' => $customer->updated_at?->toIso8601String(),
            ],
            'orders' => $orders,
            'credit' => $this->credit($actor, $storeId, $channel, $customerId),
            'communications' => $this->communications($actor, $storeId, $user, $limit),
        ];
    }

    /** @return array<string, mixed> */
    private function credit(User $actor, int $storeId, string $channel, int $customerId): array
    {
        if ($channel !== 'b2b') {
            return ['state' => 'not_applicable', 'snapshot' => null];
        }
        if (! in_array($storeId, $this->scope->allowedStoreIds($actor, 'finance.view', $channel), true)) {
            return ['state' => 'forbidden', 'snapshot' => null];
        }
        try {
            // Existing canonical aggregate contract; no full-history rows or per-order queries.
            // The result key is a correlation identity, not a persisted synthetic order.
            $snapshots = $this->ledger->orderRowSettlements([[
                'order_id' => $customerId,
                'customer_id' => $customerId,
                'store_id' => $storeId,
                'invoice_id' => null,
                'invoice_status' => null,
                'invoice_total' => null,
                'payment_method' => null,
            ]]);

            return [
                'state' => isset($snapshots[$customerId]) ? 'current_read' : 'missing',
                'authority' => B2bAccountLedgerService::class,
                'snapshot' => $snapshots[$customerId] ?? null,
            ];
        } catch (Throwable) {
            return ['state' => 'unavailable', 'snapshot' => null];
        }
    }

    /** @return array<string, mixed> */
    private function communications(User $actor, int $storeId, ?User $customerUser, int $limit): array
    {
        if (! in_array($storeId, $this->scope->allowedStoreIds($actor, 'whatsapp.conversations.view'), true)) {
            return ['state' => 'forbidden', 'items' => []];
        }
        try {
            if (! $this->whatsapp->allowsAgentInbox()) {
                return ['state' => 'disabled', 'items' => []];
            }
            $platform = $customerUser === null ? null : $this->platformCustomers->forUser($customerUser);
            if ($platform === null) {
                return ['state' => 'missing', 'items' => []];
            }
            $supervisor = $actor->hasRole('SUPER_ADMIN') || $actor->hasPermission('whatsapp.customer_service.supervise', $storeId);
            $items = DB::table('whats_app_conversations')
                ->where('store_id', $storeId)
                ->where('platform_customer_id', $platform->getKey())
                ->when(! $supervisor, fn ($query) => $query->where(fn ($visibility) => $visibility
                    ->whereNull('primary_agent_id')->orWhere('primary_agent_id', $actor->getKey())))
                ->orderByDesc('id')
                ->limit($limit)
                ->get(['id', 'status', 'updated_at'])
                ->map(static fn (object $row): array => [
                    'id' => (int) $row->id,
                    'status' => (string) $row->status,
                    'updated_at' => $row->updated_at,
                    'url' => route('admin.whatsapp-inbox.index', ['conversation' => $row->id]),
                ])->all();

            return ['state' => 'current_read', 'items' => $items];
        } catch (Throwable) {
            return ['state' => 'unavailable', 'items' => []];
        }
    }
}
