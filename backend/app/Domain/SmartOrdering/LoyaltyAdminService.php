<?php

namespace App\Domain\SmartOrdering;

use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LoyaltyAdminService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array<string,mixed> */
    public function configureRule(
        User $actor,
        SmartOrderingSite $site,
        float $pointsPerCurrencyUnit,
        float $minimumSpend,
        ?int $expiryDays,
        ?int $branchId = null,
        bool $active = true,
    ): array {
        abort_unless($actor->hasPermission('smart_ordering.manage', (int) $site->store_id), 403);
        abort_if($pointsPerCurrencyUnit < 0 || $minimumSpend < 0, 422);
        abort_if($expiryDays !== null && $expiryDays < 1, 422);

        if ($branchId !== null) {
            abort_unless(
                DB::table('smart_ordering_branches')
                    ->where('id', $branchId)
                    ->where('site_id', $site->id)
                    ->exists(),
                404,
            );
        }

        $before = DB::table('smart_ordering_loyalty_rules')
            ->where('site_id', $site->id)
            ->when($branchId === null, fn ($q) => $q->whereNull('branch_id'), fn ($q) => $q->where('branch_id', $branchId))
            ->first();

        if ($before === null) {
            DB::table('smart_ordering_loyalty_rules')->insert([
                'site_id' => $site->id,
                'branch_id' => $branchId,
                'points_per_currency_unit' => $pointsPerCurrencyUnit,
                'minimum_spend' => $minimumSpend,
                'expiry_days' => $expiryDays,
                'is_active' => $active,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('smart_ordering_loyalty_rules')->where('id', $before->id)->update([
                'points_per_currency_unit' => $pointsPerCurrencyUnit,
                'minimum_spend' => $minimumSpend,
                'expiry_days' => $expiryDays,
                'is_active' => $active,
                'updated_at' => now(),
            ]);
        }

        $after = DB::table('smart_ordering_loyalty_rules')
            ->where('site_id', $site->id)
            ->when($branchId === null, fn ($q) => $q->whereNull('branch_id'), fn ($q) => $q->where('branch_id', $branchId))
            ->first();

        $this->audit->record('smart_ordering.loyalty.rule_updated', $actor, $site, [
            'store_id' => (int) $site->store_id,
            'branch_id' => $branchId,
            'points_per_currency_unit' => $before?->points_per_currency_unit,
            'minimum_spend' => $before?->minimum_spend,
            'expiry_days' => $before?->expiry_days,
            'is_active' => $before?->is_active,
        ], [
            'store_id' => (int) $site->store_id,
            'branch_id' => $branchId,
            'points_per_currency_unit' => (float) $after->points_per_currency_unit,
            'minimum_spend' => (float) $after->minimum_spend,
            'expiry_days' => $after->expiry_days === null ? null : (int) $after->expiry_days,
            'is_active' => (bool) $after->is_active,
        ]);

        return [
            'id' => (int) $after->id,
            'branch_id' => $branchId,
            'points_per_currency_unit' => (float) $after->points_per_currency_unit,
            'minimum_spend' => (float) $after->minimum_spend,
            'expiry_days' => $after->expiry_days === null ? null : (int) $after->expiry_days,
            'is_active' => (bool) $after->is_active,
        ];
    }

    /** @return array<string,mixed> */
    public function createReward(
        User $actor,
        SmartOrderingSite $site,
        string $nameEn,
        ?string $nameAr,
        int $pointsCost,
        ?int $branchId = null,
        array $payload = [],
    ): array {
        abort_unless($actor->hasPermission('smart_ordering.manage', (int) $site->store_id), 403);
        abort_if($pointsCost <= 0, 422);

        if ($branchId !== null) {
            abort_unless(
                DB::table('smart_ordering_branches')
                    ->where('id', $branchId)
                    ->where('site_id', $site->id)
                    ->exists(),
                404,
            );
        }

        $publicId = (string) Str::uuid();
        $id = (int) DB::table('smart_ordering_loyalty_rewards')->insertGetId([
            'public_id' => $publicId,
            'site_id' => $site->id,
            'branch_id' => $branchId,
            'name_ar' => $nameAr === null ? null : mb_substr(trim($nameAr), 0, 255),
            'name_en' => mb_substr(trim($nameEn), 0, 255),
            'points_cost' => $pointsCost,
            'payload' => $payload === [] ? null : json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit->record('smart_ordering.loyalty.reward_created', $actor, $site, null, [
            'store_id' => (int) $site->store_id,
            'reward_public_id' => $publicId,
            'branch_id' => $branchId,
            'points_cost' => $pointsCost,
        ]);

        return [
            'id' => $id,
            'public_id' => $publicId,
            'branch_id' => $branchId,
            'name_ar' => $nameAr,
            'name_en' => $nameEn,
            'points_cost' => $pointsCost,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function rewards(SmartOrderingSite $site, ?int $branchId = null): array
    {
        return DB::table('smart_ordering_loyalty_rewards')
            ->where('site_id', $site->id)
            ->where('is_active', true)
            ->where(function ($query) use ($branchId): void {
                $query->whereNull('branch_id');
                if ($branchId !== null) {
                    $query->orWhere('branch_id', $branchId);
                }
            })
            ->orderBy('points_cost')
            ->get(['public_id', 'branch_id', 'name_ar', 'name_en', 'points_cost'])
            ->map(static fn ($row): array => [
                'id' => (string) $row->public_id,
                'branch_id' => $row->branch_id === null ? null : (int) $row->branch_id,
                'name_ar' => $row->name_ar,
                'name_en' => (string) $row->name_en,
                'points_cost' => (int) $row->points_cost,
            ])->values()->all();
    }
}
