<?php

namespace App\Domain\Assistant\Security;

use App\Models\User;
use App\Services\AuditLogger;
use App\Services\OperationalTenantScope;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RetailerSellOutAccessPolicy
{
    public const PURPOSE_ASSISTANT_ANALYTICS = 'assistant_analytics';

    public const BASIS_INTERNAL = 'INTERNAL';

    public const BASIS_OPT_IN = 'OPT_IN';

    public const BASIS_CONTRACT = 'CONTRACT';

    private const BASES = [
        self::BASIS_INTERNAL,
        self::BASIS_OPT_IN,
        self::BASIS_CONTRACT,
    ];

    public function __construct(
        private readonly OperationalTenantScope $scope,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function grant(
        User $actor,
        int $retailerStoreId,
        int $wholesaleStoreId,
        string $basis,
        string $purpose = self::PURPOSE_ASSISTANT_ANALYTICS,
        int $retentionDays = 30,
        ?DateTimeInterface $expiresAt = null,
    ): array {
        $this->scope->assertStore($actor, $retailerStoreId, 'settings.manage', 'b2c');
        abort_unless($this->scope->storeChannel($wholesaleStoreId) === 'b2b', 404);

        abort_unless(
            DB::table('retail_wholesale_accounts')
                ->where('retail_store_id', $retailerStoreId)
                ->exists(),
            409,
            'Retail store must have an authoritative Wholesale account link before sell-out sharing can be granted.',
        );

        $basis = strtoupper(trim($basis));
        if (! in_array($basis, self::BASES, true)) {
            throw ValidationException::withMessages([
                'basis' => ['Sell-out sharing basis must be INTERNAL, OPT_IN, or CONTRACT.'],
            ]);
        }

        $purpose = $this->purpose($purpose);
        if ($retentionDays < 1 || $retentionDays > 3650) {
            throw ValidationException::withMessages([
                'retention_days' => ['Retention days must be between 1 and 3650.'],
            ]);
        }

        if ($expiresAt !== null && $expiresAt->getTimestamp() <= now()->getTimestamp()) {
            throw ValidationException::withMessages([
                'expires_at' => ['Sell-out sharing expiry must be in the future.'],
            ]);
        }

        return DB::transaction(function () use (
            $actor,
            $retailerStoreId,
            $wholesaleStoreId,
            $basis,
            $purpose,
            $retentionDays,
            $expiresAt,
        ): array {
            $existing = DB::table('assistant_retailer_data_grants')
                ->where('retailer_store_id', $retailerStoreId)
                ->where('wholesale_store_id', $wholesaleStoreId)
                ->where('purpose', $purpose)
                ->lockForUpdate()
                ->first();

            $before = $existing === null ? null : (array) $existing;
            $now = now();
            $startsAt = $now->copy()->subSecond();

            DB::table('assistant_retailer_data_grants')->updateOrInsert(
                [
                    'retailer_store_id' => $retailerStoreId,
                    'wholesale_store_id' => $wholesaleStoreId,
                    'purpose' => $purpose,
                ],
                [
                    'basis' => $basis,
                    'status' => 'active',
                    'retention_days' => $retentionDays,
                    'starts_at' => $startsAt,
                    'expires_at' => $expiresAt,
                    'granted_by_user_id' => $actor->getKey(),
                    'revoked_at' => null,
                    'revoked_by_user_id' => null,
                    'created_at' => $existing?->created_at ?? $now,
                    'updated_at' => $now,
                ],
            );

            $row = DB::table('assistant_retailer_data_grants')
                ->where('retailer_store_id', $retailerStoreId)
                ->where('wholesale_store_id', $wholesaleStoreId)
                ->where('purpose', $purpose)
                ->firstOrFail();

            $after = (array) $row;
            $after['store_id'] = $retailerStoreId;
            $this->audit->record(
                'assistant.sellout.consent.granted',
                $actor,
                null,
                $before,
                $after,
            );

            return $this->grantPayload($row);
        }, 3);
    }

    public function revoke(
        User $actor,
        int $retailerStoreId,
        int $wholesaleStoreId,
        string $purpose = self::PURPOSE_ASSISTANT_ANALYTICS,
    ): void {
        $this->scope->assertStore($actor, $retailerStoreId, 'settings.manage', 'b2c');
        $purpose = $this->purpose($purpose);

        DB::transaction(function () use ($actor, $retailerStoreId, $wholesaleStoreId, $purpose): void {
            $row = DB::table('assistant_retailer_data_grants')
                ->where('retailer_store_id', $retailerStoreId)
                ->where('wholesale_store_id', $wholesaleStoreId)
                ->where('purpose', $purpose)
                ->lockForUpdate()
                ->first();

            abort_unless($row !== null, 404);

            if ((string) $row->status === 'revoked') {
                return;
            }

            $before = (array) $row;
            DB::table('assistant_retailer_data_grants')
                ->where('id', $row->id)
                ->update([
                    'status' => 'revoked',
                    'revoked_at' => now(),
                    'revoked_by_user_id' => $actor->getKey(),
                    'updated_at' => now(),
                ]);

            $after = (array) DB::table('assistant_retailer_data_grants')
                ->where('id', $row->id)
                ->firstOrFail();
            $after['store_id'] = $retailerStoreId;

            $this->audit->record(
                'assistant.sellout.consent.revoked',
                $actor,
                null,
                $before,
                $after,
            );
        }, 3);
    }

    /**
     * @return array<string, mixed>
     */
    public function assertCanRead(
        User $actor,
        int $retailerStoreId,
        ?int $wholesaleStoreId = null,
        string $purpose = self::PURPOSE_ASSISTANT_ANALYTICS,
        string $locale = 'en',
    ): array {
        abort_unless($this->scope->storeChannel($retailerStoreId) === 'b2c', 404);
        $purpose = $this->purpose($purpose);

        $ownRetailIds = array_values(array_intersect(
            $this->scope->allowedStoreIds($actor, 'assistant.use', 'b2c'),
            $this->scope->allowedStoreIds($actor, 'reports.view', 'b2c'),
        ));

        if (in_array($retailerStoreId, $ownRetailIds, true)) {
            $payload = [
                'grant_id' => null,
                'retailer_store_id' => $retailerStoreId,
                'wholesale_store_id' => null,
                'basis' => 'OWN_RETAIL_SCOPE',
                'purpose' => $purpose,
                'retention_days' => null,
            ];
            $this->recordRead($actor, $payload);

            return $payload;
        }

        $wholesaleIds = $this->permittedWholesaleStoreIds($actor);
        if ($wholesaleStoreId !== null) {
            if (! in_array($wholesaleStoreId, $wholesaleIds, true)) {
                $this->deny($locale);
            }
            $wholesaleIds = [$wholesaleStoreId];
        }

        if ($wholesaleIds === []) {
            $this->deny($locale);
        }

        if (! DB::table('retail_wholesale_accounts')->where('retail_store_id', $retailerStoreId)->exists()) {
            $this->deny($locale);
        }

        $row = $this->activeGrantQuery($purpose)
            ->where('retailer_store_id', $retailerStoreId)
            ->whereIn('wholesale_store_id', $wholesaleIds)
            ->orderByDesc('id')
            ->first();

        if ($row === null) {
            $this->deny($locale);
        }

        $payload = $this->grantPayload($row);
        $this->recordRead($actor, $payload);

        return $payload;
    }

    /**
     * @param list<int> $retailerStoreIds
     * @return list<array<string, mixed>>
     */
    public function assertCanReadMany(
        User $actor,
        array $retailerStoreIds,
        ?int $wholesaleStoreId = null,
        string $purpose = self::PURPOSE_ASSISTANT_ANALYTICS,
        string $locale = 'en',
    ): array {
        $ids = collect($retailerStoreIds)
            ->map(fn ($id): ?int => $this->positiveInt($id))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($ids === [] || count($ids) !== count(array_unique($retailerStoreIds)) || count($ids) > 100) {
            throw ValidationException::withMessages([
                'retailer_store_ids' => ['Provide between 1 and 100 unique positive retailer store ids.'],
            ]);
        }

        $grants = [];
        foreach ($ids as $retailerStoreId) {
            $grants[] = $this->assertCanRead(
                $actor,
                $retailerStoreId,
                $wholesaleStoreId,
                $purpose,
                $locale,
            );
        }

        return $grants;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function grantsForPrincipal(
        User $actor,
        string $purpose = self::PURPOSE_ASSISTANT_ANALYTICS,
    ): array {
        $wholesaleStoreIds = $this->permittedWholesaleStoreIds($actor);
        if ($wholesaleStoreIds === []) {
            return [];
        }

        return $this->activeGrantQuery($this->purpose($purpose))
            ->join('retail_wholesale_accounts as rwa', 'rwa.retail_store_id', '=', 'assistant_retailer_data_grants.retailer_store_id')
            ->whereIn('assistant_retailer_data_grants.wholesale_store_id', $wholesaleStoreIds)
            ->orderBy('assistant_retailer_data_grants.retailer_store_id')
            ->get('assistant_retailer_data_grants.*')
            ->map(fn ($row): array => $this->grantPayload($row))
            ->values()
            ->all();
    }

    /** @return list<int> */
    private function permittedWholesaleStoreIds(User $actor): array
    {
        $assistant = $this->scope->allowedStoreIds($actor, 'assistant.use', 'b2b');
        $reports = $this->scope->allowedStoreIds($actor, 'reports.view', 'b2b');
        $ids = array_values(array_intersect($assistant, $reports));
        sort($ids);

        return $ids;
    }

    private function activeGrantQuery(string $purpose): Builder
    {
        return DB::table('assistant_retailer_data_grants')
            ->where('purpose', $purpose)
            ->where('status', 'active')
            ->where('starts_at', '<=', now())
            ->whereNull('revoked_at')
            ->where(function (Builder $query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    /** @return array<string, mixed> */
    private function grantPayload(object $row): array
    {
        return [
            'grant_id' => (int) $row->id,
            'retailer_store_id' => (int) $row->retailer_store_id,
            'wholesale_store_id' => (int) $row->wholesale_store_id,
            'basis' => (string) $row->basis,
            'purpose' => (string) $row->purpose,
            'retention_days' => (int) $row->retention_days,
            'starts_at' => (string) $row->starts_at,
            'expires_at' => $row->expires_at === null ? null : (string) $row->expires_at,
        ];
    }

    /** @param array<string, mixed> $grant */
    private function recordRead(User $actor, array $grant): void
    {
        $this->audit->record(
            'assistant.sellout.read',
            $actor,
            null,
            null,
            [
                'store_id' => $grant['retailer_store_id'] ?? null,
                'retailer_store_id' => $grant['retailer_store_id'] ?? null,
                'wholesale_store_id' => $grant['wholesale_store_id'] ?? null,
                'grant_id' => $grant['grant_id'] ?? null,
                'basis' => $grant['basis'] ?? null,
                'purpose' => $grant['purpose'] ?? null,
            ],
        );
    }

    private function purpose(string $purpose): string
    {
        $purpose = strtolower(trim($purpose));

        if ($purpose === '' || strlen($purpose) > 64 || preg_match('/^[a-z0-9][a-z0-9._:-]*$/', $purpose) !== 1) {
            throw ValidationException::withMessages([
                'purpose' => ['Sell-out sharing purpose is invalid.'],
            ]);
        }

        return $purpose;
    }

    private function positiveInt(mixed $value): ?int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($integer) && $integer > 0 ? $integer : null;
    }

    private function deny(string $locale): never
    {
        abort(
            403,
            strtolower($locale) === 'ar'
                ? 'بيانات البيع للمستهلك غير متاحة للمساعد لهذا الطلب.'
                : 'Retail sell-out data is not shared for this Assistant request.',
        );
    }
}
