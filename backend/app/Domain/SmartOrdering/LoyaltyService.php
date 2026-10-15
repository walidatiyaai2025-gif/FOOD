<?php

namespace App\Domain\SmartOrdering;

use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class LoyaltyService
{
    public function __construct(
        private readonly SmartOrderingRuntimePolicy $runtime,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array<string,int> */
    public function balance(SmartOrderingSite $site, int $customerId): array
    {
        $this->assertEnabled($site);
        $this->assertTenantCustomer($site, $customerId);

        $account = DB::table('smart_ordering_loyalty_accounts')
            ->where('site_id', $site->id)
            ->where('customer_id', $customerId)
            ->first(['balance', 'lifetime_earned', 'lifetime_redeemed']);

        return [
            'balance' => (int) ($account->balance ?? 0),
            'lifetime_earned' => (int) ($account->lifetime_earned ?? 0),
            'lifetime_redeemed' => (int) ($account->lifetime_redeemed ?? 0),
        ];
    }

    /** @return array<string,mixed> */
    public function earnForCompletedOrder(
        SmartOrderingSite $site,
        int $orderId,
        ?int $branchId = null,
    ): array {
        $this->assertEnabled($site);

        $order = DB::table('orders')
            ->where('id', $orderId)
            ->where('store_id', $site->store_id)
            ->whereRaw('LOWER(channel) = ?', ['b2c'])
            ->first(['id', 'customer_id', 'grand_total', 'status']);

        abort_unless($order !== null, 404);
        abort_unless(strtolower((string) $order->status) === 'completed', 409, 'Loyalty points are earned only after completion.');

        $this->assertBranch($site, $branchId);
        $rule = $this->rule($site, $branchId);
        if (! $rule['is_active'] || (float) $order->grand_total < $rule['minimum_spend']) {
            return ['points' => 0, 'balance' => $this->balance($site, (int) $order->customer_id)['balance']];
        }

        $points = max(0, (int) floor(((float) $order->grand_total) * $rule['points_per_currency_unit']));
        if ($points === 0) {
            return ['points' => 0, 'balance' => $this->balance($site, (int) $order->customer_id)['balance']];
        }

        $key = 'earn:order:'.$orderId;

        return DB::transaction(function () use ($site, $order, $orderId, $branchId, $rule, $points, $key): array {
            $existing = DB::table('smart_ordering_loyalty_ledger')
                ->where('idempotency_key', $key)
                ->first(['points', 'balance_after']);
            if ($existing !== null) {
                return ['points' => (int) $existing->points, 'balance' => (int) $existing->balance_after];
            }

            $account = $this->lockedAccount($site, (int) $order->customer_id);
            $existing = DB::table('smart_ordering_loyalty_ledger')
                ->where('idempotency_key', $key)
                ->first(['points', 'balance_after']);
            if ($existing !== null) {
                return ['points' => (int) $existing->points, 'balance' => (int) $existing->balance_after];
            }

            $balance = (int) $account->balance + $points;
            $expiresAt = $rule['expiry_days'] === null ? null : now()->addDays($rule['expiry_days']);

            DB::table('smart_ordering_loyalty_ledger')->insert([
                'account_id' => $account->id,
                'site_id' => $site->id,
                'customer_id' => $order->customer_id,
                'branch_id' => $branchId,
                'order_id' => $orderId,
                'reward_id' => null,
                'created_by' => null,
                'type' => 'EARN',
                'points' => $points,
                'balance_after' => $balance,
                'idempotency_key' => $key,
                'metadata' => json_encode(['grand_total' => (float) $order->grand_total], JSON_THROW_ON_ERROR),
                'expires_at' => $expiresAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('smart_ordering_loyalty_accounts')->where('id', $account->id)->update([
                'balance' => $balance,
                'lifetime_earned' => (int) $account->lifetime_earned + $points,
                'updated_at' => now(),
            ]);

            return ['points' => $points, 'balance' => $balance];
        }, 3);
    }

    /** @return array<string,mixed> */
    public function redeemReward(
        SmartOrderingSite $site,
        int $customerId,
        string $rewardPublicId,
        ?int $branchId,
        string $idempotencyKey,
    ): array {
        $this->assertEnabled($site);
        $this->assertTenantCustomer($site, $customerId);
        $this->assertBranch($site, $branchId);
        abort_if(trim($idempotencyKey) === '', 422);

        return DB::transaction(function () use ($site, $customerId, $rewardPublicId, $branchId, $idempotencyKey): array {
            $key = 'redeem:'.hash('sha256', $site->id.'|'.$customerId.'|'.$idempotencyKey);
            $existing = DB::table('smart_ordering_loyalty_ledger')
                ->where('idempotency_key', $key)
                ->first(['reward_id', 'points', 'balance_after']);
            if ($existing !== null) {
                return [
                    'reward_id' => (int) $existing->reward_id,
                    'points' => abs((int) $existing->points),
                    'balance' => (int) $existing->balance_after,
                    'idempotent' => true,
                ];
            }

            $reward = DB::table('smart_ordering_loyalty_rewards')
                ->where('site_id', $site->id)
                ->where('public_id', $rewardPublicId)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first(['id', 'branch_id', 'points_cost', 'name_en']);
            abort_unless($reward !== null, 404);
            abort_if($reward->branch_id !== null && (int) $reward->branch_id !== (int) $branchId, 409, 'Reward is not valid for this branch.');

            $account = $this->lockedAccount($site, $customerId);
            $existing = DB::table('smart_ordering_loyalty_ledger')
                ->where('idempotency_key', $key)
                ->first(['reward_id', 'points', 'balance_after']);
            if ($existing !== null) {
                return [
                    'reward_id' => (int) $existing->reward_id,
                    'points' => abs((int) $existing->points),
                    'balance' => (int) $existing->balance_after,
                    'idempotent' => true,
                ];
            }

            $cost = (int) $reward->points_cost;
            if ($cost <= 0 || (int) $account->balance < $cost) {
                throw ValidationException::withMessages(['loyalty' => ['Insufficient loyalty balance.']]);
            }

            $balance = (int) $account->balance - $cost;
            DB::table('smart_ordering_loyalty_ledger')->insert([
                'account_id' => $account->id,
                'site_id' => $site->id,
                'customer_id' => $customerId,
                'branch_id' => $branchId,
                'order_id' => null,
                'reward_id' => $reward->id,
                'created_by' => null,
                'type' => 'REDEEM',
                'points' => -$cost,
                'balance_after' => $balance,
                'idempotency_key' => $key,
                'metadata' => json_encode(['reward_name' => (string) $reward->name_en], JSON_THROW_ON_ERROR),
                'expires_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('smart_ordering_loyalty_accounts')->where('id', $account->id)->update([
                'balance' => $balance,
                'lifetime_redeemed' => (int) $account->lifetime_redeemed + $cost,
                'updated_at' => now(),
            ]);

            return ['reward_id' => (int) $reward->id, 'points' => $cost, 'balance' => $balance, 'idempotent' => false];
        }, 3);
    }

    /** @return array<string,int> */
    public function reverseOrder(SmartOrderingSite $site, int $orderId): array
    {
        $this->assertEnabled($site);
        $key = 'reversal:order:'.$orderId;

        return DB::transaction(function () use ($site, $orderId, $key): array {
            $existing = DB::table('smart_ordering_loyalty_ledger')
                ->where('idempotency_key', $key)
                ->first(['points', 'balance_after']);
            if ($existing !== null) {
                return ['points_reversed' => abs((int) $existing->points), 'balance' => (int) $existing->balance_after];
            }

            $earned = DB::table('smart_ordering_loyalty_ledger')
                ->where('site_id', $site->id)
                ->where('order_id', $orderId)
                ->where('type', 'EARN')
                ->first();
            if ($earned === null) {
                return ['points_reversed' => 0, 'balance' => 0];
            }

            $account = DB::table('smart_ordering_loyalty_accounts')->where('id', $earned->account_id)->lockForUpdate()->first();
            abort_unless($account !== null, 409);

            $existing = DB::table('smart_ordering_loyalty_ledger')
                ->where('idempotency_key', $key)
                ->first(['points', 'balance_after']);
            if ($existing !== null) {
                return ['points_reversed' => abs((int) $existing->points), 'balance' => (int) $existing->balance_after];
            }

            $reversed = min((int) $earned->points, max(0, (int) $account->balance));
            $balance = (int) $account->balance - $reversed;

            DB::table('smart_ordering_loyalty_ledger')->insert([
                'account_id' => $account->id,
                'site_id' => $site->id,
                'customer_id' => $earned->customer_id,
                'branch_id' => $earned->branch_id,
                'order_id' => $orderId,
                'reward_id' => null,
                'created_by' => null,
                'type' => 'REVERSAL',
                'points' => -$reversed,
                'balance_after' => $balance,
                'idempotency_key' => $key,
                'metadata' => json_encode([
                    'original_points' => (int) $earned->points,
                    'unrecoverable_points' => max(0, (int) $earned->points - $reversed),
                ], JSON_THROW_ON_ERROR),
                'expires_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('smart_ordering_loyalty_accounts')->where('id', $account->id)->update([
                'balance' => $balance,
                'updated_at' => now(),
            ]);

            return ['points_reversed' => $reversed, 'balance' => $balance];
        }, 3);
    }

    /** @return array<string,int> */
    public function adjust(
        User $actor,
        SmartOrderingSite $site,
        int $customerId,
        int $points,
        string $reason,
    ): array {
        $this->assertEnabled($site);
        abort_unless($actor->hasPermission('smart_ordering.manage', (int) $site->store_id), 403);
        $this->assertTenantCustomer($site, $customerId);
        abort_if($points === 0, 422);

        $result = DB::transaction(function () use ($site, $customerId, $points, $reason, $actor): array {
            $account = $this->lockedAccount($site, $customerId);
            $balance = (int) $account->balance + $points;
            if ($balance < 0) {
                throw ValidationException::withMessages(['points' => ['Adjustment cannot make loyalty balance negative.']]);
            }

            $key = 'adjust:'.Str::uuid();
            DB::table('smart_ordering_loyalty_ledger')->insert([
                'account_id' => $account->id,
                'site_id' => $site->id,
                'customer_id' => $customerId,
                'branch_id' => null,
                'order_id' => null,
                'reward_id' => null,
                'created_by' => $actor->getKey(),
                'type' => 'ADJUSTMENT',
                'points' => $points,
                'balance_after' => $balance,
                'idempotency_key' => $key,
                'metadata' => json_encode(['reason' => mb_substr(trim($reason), 0, 500)], JSON_THROW_ON_ERROR),
                'expires_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('smart_ordering_loyalty_accounts')->where('id', $account->id)->update([
                'balance' => $balance,
                'lifetime_earned' => (int) $account->lifetime_earned + max(0, $points),
                'lifetime_redeemed' => (int) $account->lifetime_redeemed + max(0, -$points),
                'updated_at' => now(),
            ]);

            return ['balance_before' => (int) $account->balance, 'balance' => $balance];
        }, 3);

        $this->audit->record('smart_ordering.loyalty.adjusted', $actor, $site, [
            'store_id' => (int) $site->store_id,
            'customer_id' => $customerId,
            'balance' => $result['balance_before'],
        ], [
            'store_id' => (int) $site->store_id,
            'customer_id' => $customerId,
            'points' => $points,
            'balance' => $result['balance'],
            'reason' => mb_substr(trim($reason), 0, 500),
        ]);

        return ['points' => $points, 'balance' => $result['balance']];
    }

    public function expireDue(SmartOrderingSite $site, ?int $customerId = null): int
    {
        $this->assertEnabled($site);

        $query = DB::table('smart_ordering_loyalty_ledger')
            ->where('site_id', $site->id)
            ->where('type', 'EARN')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id');

        if ($customerId !== null) {
            $this->assertTenantCustomer($site, $customerId);
            $query->where('customer_id', $customerId);
        }

        $expired = 0;
        foreach ($query->get() as $earning) {
            $key = 'expire:ledger:'.$earning->id;
            $expired += DB::transaction(function () use ($site, $earning, $key): int {
                if (DB::table('smart_ordering_loyalty_ledger')->where('idempotency_key', $key)->exists()) {
                    return 0;
                }

                $account = DB::table('smart_ordering_loyalty_accounts')->where('id', $earning->account_id)->lockForUpdate()->first();
                if ($account === null || (int) $account->balance <= 0) {
                    return 0;
                }
                if (DB::table('smart_ordering_loyalty_ledger')->where('idempotency_key', $key)->exists()) {
                    return 0;
                }

                $points = min((int) $earning->points, (int) $account->balance);
                $balance = (int) $account->balance - $points;
                DB::table('smart_ordering_loyalty_ledger')->insert([
                    'account_id' => $account->id,
                    'site_id' => $site->id,
                    'customer_id' => $earning->customer_id,
                    'branch_id' => $earning->branch_id,
                    'order_id' => $earning->order_id,
                    'reward_id' => null,
                    'created_by' => null,
                    'type' => 'EXPIRY',
                    'points' => -$points,
                    'balance_after' => $balance,
                    'idempotency_key' => $key,
                    'metadata' => json_encode(['source_ledger_id' => (int) $earning->id], JSON_THROW_ON_ERROR),
                    'expires_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('smart_ordering_loyalty_accounts')->where('id', $account->id)->update([
                    'balance' => $balance,
                    'updated_at' => now(),
                ]);

                return $points;
            }, 3);
        }

        return $expired;
    }

    /** @return array{points_per_currency_unit:float,minimum_spend:float,expiry_days:?int,is_active:bool} */
    private function rule(SmartOrderingSite $site, ?int $branchId): array
    {
        $row = null;
        if ($branchId !== null) {
            $row = DB::table('smart_ordering_loyalty_rules')
                ->where('site_id', $site->id)
                ->where('scope_key', 'branch:'.$branchId)
                ->first();
        }

        $row ??= DB::table('smart_ordering_loyalty_rules')
            ->where('site_id', $site->id)
            ->where('scope_key', 'site')
            ->first();

        return [
            'points_per_currency_unit' => (float) ($row->points_per_currency_unit ?? config('smart_ordering.loyalty.default_points_per_currency_unit', 1)),
            'minimum_spend' => (float) ($row->minimum_spend ?? config('smart_ordering.loyalty.default_minimum_spend', 0)),
            'expiry_days' => $row?->expiry_days === null
                ? config('smart_ordering.loyalty.default_expiry_days')
                : (int) $row->expiry_days,
            'is_active' => $row === null ? true : (bool) $row->is_active,
        ];
    }

    private function lockedAccount(SmartOrderingSite $site, int $customerId): object
    {
        $this->assertTenantCustomer($site, $customerId);

        $query = DB::table('smart_ordering_loyalty_accounts')
            ->where('site_id', $site->id)
            ->where('customer_id', $customerId);

        $account = (clone $query)->lockForUpdate()->first();
        if ($account === null) {
            DB::table('smart_ordering_loyalty_accounts')->insertOrIgnore([
                'site_id' => $site->id,
                'customer_id' => $customerId,
                'balance' => 0,
                'lifetime_earned' => 0,
                'lifetime_redeemed' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $account = (clone $query)->lockForUpdate()->first();
        }

        abort_unless($account !== null, 409, 'Unable to initialize loyalty account.');

        return $account;
    }

    private function assertTenantCustomer(SmartOrderingSite $site, int $customerId): void
    {
        $visible = DB::table('b2c_customers')
            ->where('store_id', $site->store_id)
            ->where('legacy_customer_id', $customerId)
            ->exists()
            || DB::table('orders')
                ->where('store_id', $site->store_id)
                ->where('customer_id', $customerId)
                ->whereRaw('LOWER(channel) = ?', ['b2c'])
                ->exists();

        abort_unless($visible, 404);
    }

    private function assertBranch(SmartOrderingSite $site, ?int $branchId): void
    {
        if ($branchId === null) {
            return;
        }

        abort_unless(
            DB::table('smart_ordering_branches')->where('id', $branchId)->where('site_id', $site->id)->where('is_active', true)->exists(),
            404,
        );
    }

    private function assertEnabled(SmartOrderingSite $site): void
    {
        abort_unless($this->runtime->entitled($site, Module::LOYALTY), 404);
    }
}
