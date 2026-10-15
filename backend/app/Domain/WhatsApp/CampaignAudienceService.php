<?php

namespace App\Domain\WhatsApp;

use App\Models\User;
use App\Services\AuditLogger;
use App\Services\OperationalTenantScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CampaignAudienceService
{
    public function __construct(
        private readonly MarketingConsentService $consent,
        private readonly OperationalTenantScope $scope,
        private readonly AuditLogger $audit,
    ) {}

    /** @return list<array<string,mixed>> */
    public function search(User $actor, array $filters): array
    {
        $allowed = $this->scope->allowedStoreIds($actor, 'whatsapp.campaigns.view');
        if (! $actor->hasRole('SUPER_ADMIN') && $allowed === []) {
            return [];
        }

        $query = DB::table('platform_customers as pc')
            ->leftJoin('stores as s', 's.id', '=', 'pc.origin_store_id')
            ->where('pc.is_active', true)
            ->whereNotNull('pc.phone')
            ->when(! $actor->hasRole('SUPER_ADMIN'), fn ($q) => $q->whereIn('pc.origin_store_id', $allowed))
            ->select([
                'pc.id',
                'pc.name',
                'pc.phone',
                'pc.email',
                'pc.origin_channel',
                'pc.origin_store_id',
                's.name as store_name',
            ]);

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';
            $query->where(function ($builder) use ($like): void {
                $builder->where('pc.name', 'like', $like)
                    ->orWhere('pc.phone', 'like', $like)
                    ->orWhere('pc.email', 'like', $like);
            });
        }

        $storeId = (int) ($filters['store_id'] ?? 0);
        if ($storeId > 0) {
            $this->scope->assertStore($actor, $storeId, 'whatsapp.campaigns.view');
            $query->where('pc.origin_store_id', $storeId);
        }

        $channel = strtolower(trim((string) ($filters['channel'] ?? '')));
        if (in_array($channel, ['b2b', 'b2c'], true)) {
            $query->where('pc.origin_channel', $channel);
        }

        return $query
            ->orderBy('pc.name')
            ->limit(50)
            ->get()
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'phone' => $this->consent->normalizePhone($row->phone),
                'email' => $row->email,
                'channel' => $row->origin_channel,
                'store_id' => $row->origin_store_id === null ? null : (int) $row->origin_store_id,
                'store_name' => $row->store_name,
                'consent' => $this->consent->state((int) $row->id),
            ])
            ->all();
    }

    /** @param array<string,mixed> $segment
     * @return array<string,mixed>
     */
    public function scopedSegment(User $actor, array $segment): array
    {
        $allowed = $this->scope->allowedStoreIds($actor, 'whatsapp.campaigns.manage');
        if (! $actor->hasRole('SUPER_ADMIN') && $allowed === []) {
            abort(403);
        }

        $requestedStores = $this->intList($segment['store_ids'] ?? []);
        if ($actor->hasRole('SUPER_ADMIN')) {
            $storeIds = $requestedStores !== []
                ? $requestedStores
                : $this->scope->allowedStoreIds($actor, 'whatsapp.campaigns.manage');
        } else {
            $storeIds = $requestedStores === []
                ? $allowed
                : array_values(array_intersect($requestedStores, $allowed));
            if ($requestedStores !== [] && count($storeIds) !== count($requestedStores)) {
                abort(403);
            }
        }

        $channels = array_values(array_intersect(
            array_map(static fn (mixed $value): string => strtolower(trim((string) $value)), (array) ($segment['channels'] ?? [])),
            ['b2b', 'b2c'],
        ));

        if ($channels !== []) {
            $channelStores = DB::table('stores')
                ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
                ->whereIn('stores.id', $storeIds)
                ->whereIn('store_types.code', array_map('strtoupper', $channels))
                ->pluck('stores.id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
            $storeIds = $channelStores;
        }

        $customerIds = $this->intList($segment['customer_ids'] ?? []);
        if ($customerIds !== []) {
            $visibleIds = DB::table('platform_customers')
                ->whereIn('id', $customerIds)
                ->whereIn('origin_store_id', $storeIds)
                ->where('is_active', true)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            if (count($visibleIds) !== count($customerIds)) {
                throw ValidationException::withMessages([
                    'customer_ids' => ['One or more selected customers are outside your authorized campaign scope.'],
                ]);
            }
            $customerIds = $visibleIds;

            if ($requestedStores === [] && trim((string) ($segment['search'] ?? '')) === '') {
                $storeIds = DB::table('platform_customers')
                    ->whereIn('id', $customerIds)
                    ->whereNotNull('origin_store_id')
                    ->pluck('origin_store_id')
                    ->map(static fn ($id): int => (int) $id)
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();
            }
        }

        return array_filter([
            'customer_ids' => $customerIds,
            'store_ids' => $storeIds,
            'channels' => $channels,
            'search' => mb_substr(trim((string) ($segment['search'] ?? '')), 0, 120),
            'agent_ids' => $this->intList($segment['agent_ids'] ?? []),
            'tag_ids' => $this->intList($segment['tag_ids'] ?? []),
            'category_ids' => $this->intList($segment['category_ids'] ?? []),
            'min_value' => $this->numeric($segment['min_value'] ?? null),
            'max_value' => $this->numeric($segment['max_value'] ?? null),
            'recency_days' => $this->integerOrNull($segment['recency_days'] ?? null),
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * @param  list<array{name?:mixed,phone?:mixed,email?:mixed,consent_state?:mixed,consent_source?:mixed,consent_evidence?:mixed,consent_date?:mixed}>  $rows
     * @return array{created:int,matched:int,updated:int,skipped:int,invalid:int,consent_skipped:int,customer_ids:list<int>,errors:list<array<string,mixed>>}
     */
    public function importRows(
        User $actor,
        array $rows,
        int $storeId,
        string $sourceType,
        ?string $consentState = null,
        ?string $consentSource = null,
        ?string $consentEvidence = null,
        ?int $sourceReference = null,
    ): array {
        $channel = $this->scope->assertStore($actor, $storeId, 'whatsapp.campaigns.manage');
        if (! in_array($channel, ['b2b', 'b2c'], true)) {
            throw ValidationException::withMessages(['store_id' => ['Campaign contacts require a B2B or B2C store.']]);
        }

        $consentState = $consentState === null ? null : strtolower(trim($consentState));
        if ($consentState !== null && ! in_array($consentState, [
            MarketingConsentService::GRANTED,
            MarketingConsentService::DENIED,
            MarketingConsentService::OPTED_OUT,
        ], true)) {
            throw ValidationException::withMessages(['consent_state' => ['Invalid consent state.']]);
        }
        if ($consentState !== null && (trim((string) $consentSource) === '' || trim((string) $consentEvidence) === '')) {
            throw ValidationException::withMessages([
                'consent_evidence' => ['Consent source and evidence are required when recording a consent state.'],
            ]);
        }

        $phoneMap = $this->phoneMap();
        $seen = [];
        $created = $matched = $updated = $skipped = $invalid = $consentSkipped = 0;
        $customerIds = [];
        $errors = [];

        foreach ($rows as $offset => $row) {
            $rowNumber = $offset + 1;
            $name = trim((string) ($row['name'] ?? ''));
            $phone = $this->consent->normalizePhone($row['phone'] ?? null);
            $email = strtolower(trim((string) ($row['email'] ?? '')));

            if ($name === '' || $phone === null || ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
                $invalid++;
                $errors[] = ['row' => $rowNumber, 'reason' => 'invalid_name_phone_or_email'];

                continue;
            }
            if (isset($seen[$phone])) {
                $skipped++;
                $errors[] = ['row' => $rowNumber, 'reason' => 'duplicate_in_file', 'phone' => $phone];

                continue;
            }
            $seen[$phone] = true;

            $existing = $phoneMap[$phone] ?? null;
            if (is_array($existing)) {
                $customerId = (int) $existing['id'];
                $originStoreId = (int) ($existing['origin_store_id'] ?? 0);
                $allowed = $actor->hasRole('SUPER_ADMIN')
                    || in_array($originStoreId, $this->scope->allowedStoreIds($actor, 'whatsapp.campaigns.manage'), true);
                if (! $allowed) {
                    $skipped++;
                    $errors[] = ['row' => $rowNumber, 'reason' => 'duplicate_outside_scope'];

                    continue;
                }

                $changes = [];
                if (trim((string) ($existing['name'] ?? '')) === '') {
                    $changes['name'] = $name;
                }
                if (trim((string) ($existing['email'] ?? '')) === '' && $email !== '') {
                    $changes['email'] = $email;
                }
                if ($changes !== []) {
                    $changes['updated_at'] = now();
                    DB::table('platform_customers')->where('id', $customerId)->update($changes);
                    $updated++;
                } else {
                    $matched++;
                }
            } else {
                $customerId = $this->createPlatformCustomer($name, $phone, $email, $storeId, $channel, $sourceType);
                $phoneMap[$phone] = [
                    'id' => $customerId,
                    'origin_store_id' => $storeId,
                    'name' => $name,
                    'email' => $email,
                ];
                $created++;
            }

            $customerIds[] = $customerId;

            $rowConsentState = trim((string) ($row['consent_state'] ?? ''));
            $effectiveConsentState = $rowConsentState === '' ? $consentState : strtolower($rowConsentState);
            $effectiveConsentSource = trim((string) ($row['consent_source'] ?? ''));
            $effectiveConsentSource = $effectiveConsentSource === '' ? trim((string) $consentSource) : $effectiveConsentSource;
            $effectiveConsentEvidence = trim((string) ($row['consent_evidence'] ?? ''));
            $effectiveConsentEvidence = $effectiveConsentEvidence === '' ? trim((string) $consentEvidence) : $effectiveConsentEvidence;
            $consentDate = trim((string) ($row['consent_date'] ?? ''));

            if ($effectiveConsentState !== null && ! in_array($effectiveConsentState, [
                MarketingConsentService::GRANTED,
                MarketingConsentService::DENIED,
                MarketingConsentService::OPTED_OUT,
            ], true)) {
                $consentSkipped++;
                $errors[] = ['row' => $rowNumber, 'reason' => 'invalid_consent_state'];
                $effectiveConsentState = null;
            }

            if ($effectiveConsentState !== null
                && ($effectiveConsentSource === '' || $effectiveConsentEvidence === '')) {
                $consentSkipped++;
                $errors[] = ['row' => $rowNumber, 'reason' => 'consent_evidence_required'];
                $effectiveConsentState = null;
            }

            if ($effectiveConsentState !== null) {
                $metadata = [
                    'evidence' => $effectiveConsentEvidence,
                    'source_type' => $sourceType,
                ];
                if ($sourceReference !== null) {
                    $metadata['source_reference'] = $sourceReference;
                }
                if ($consentDate !== '') {
                    $metadata['evidence_date'] = mb_substr($consentDate, 0, 64);
                }

                $this->consent->record(
                    $customerId,
                    $effectiveConsentState,
                    mb_substr($effectiveConsentSource, 0, 64),
                    (int) $actor->getKey(),
                    $metadata,
                );
            }
        }

        $customerIds = array_values(array_unique($customerIds));
        $summary = compact('created', 'matched', 'updated', 'skipped', 'invalid', 'customerIds', 'errors');

        $this->audit->record(
            'whatsapp.campaign.contacts.'.$sourceType,
            $actor,
            null,
            null,
            [
                'store_id' => $storeId,
                'channel' => $channel,
                'created' => $created,
                'matched' => $matched,
                'updated' => $updated,
                'skipped' => $skipped,
                'invalid' => $invalid,
                'consent_skipped' => $consentSkipped,
                'source_reference' => $sourceReference,
            ],
        );

        return [
            'created' => $created,
            'matched' => $matched,
            'updated' => $updated,
            'skipped' => $skipped,
            'invalid' => $invalid,
            'consent_skipped' => $consentSkipped,
            'customer_ids' => $customerIds,
            'errors' => array_slice($errors, 0, 200),
        ];
    }

    /** @return array<string,array{id:int,origin_store_id:?int,name:?string,email:?string}> */
    private function phoneMap(): array
    {
        $map = [];
        DB::table('platform_customers')
            ->whereNotNull('phone')
            ->orderBy('id')
            ->select(['id', 'phone', 'origin_store_id', 'name', 'email'])
            ->chunk(1000, function ($rows) use (&$map): void {
                foreach ($rows as $row) {
                    $phone = $this->consent->normalizePhone($row->phone);
                    if ($phone === null || isset($map[$phone])) {
                        continue;
                    }
                    $map[$phone] = [
                        'id' => (int) $row->id,
                        'origin_store_id' => $row->origin_store_id === null ? null : (int) $row->origin_store_id,
                        'name' => $row->name,
                        'email' => $row->email,
                    ];
                }
            });

        return $map;
    }

    private function createPlatformCustomer(
        string $name,
        string $phone,
        string $email,
        int $storeId,
        string $channel,
        string $sourceType,
    ): int {
        return DB::transaction(function () use ($name, $phone, $email, $storeId, $channel, $sourceType): int {
            $contactEmail = $email !== ''
                ? $email
                : 'wa-contact-'.Str::lower((string) Str::uuid()).'@contacts.foodex.local';
            // An imported marketing contact is not a provisioned login account.
            // Use a synthetic identity so an operator import cannot squat the
            // customer's real email in the authentication namespace.
            $loginEmail = 'wa-login-'.Str::lower((string) Str::uuid()).'@contacts.foodex.local';

            $user = User::query()->create([
                'name' => $name,
                'email' => $loginEmail,
                'password' => Hash::make(Str::random(64)),
                'locale' => 'ar',
                'is_active' => false,
                'is_platform_customer' => true,
                'deactivated_at' => now(),
                'deactivation_reason' => 'Imported WhatsApp campaign contact; login not provisioned.',
            ]);

            $legacyId = (int) DB::table('customers')->insertGetId([
                'user_id' => $user->getKey(),
                'type' => $channel,
                'name' => $name,
                'phone' => $phone,
                'email' => $contactEmail,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($channel === 'b2b') {
                DB::table('b2b_customers')->insert([
                    'legacy_customer_id' => $legacyId,
                    'user_id' => $user->getKey(),
                    'name' => $name,
                    'phone' => $phone,
                    'email' => $contactEmail,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('b2c_customers')->insert([
                    'legacy_customer_id' => $legacyId,
                    'store_id' => $storeId,
                    'user_id' => $user->getKey(),
                    'name' => $name,
                    'phone' => $phone,
                    'email' => $contactEmail,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return (int) DB::table('platform_customers')->insertGetId([
                'user_id' => $user->getKey(),
                'legacy_customer_id' => $legacyId,
                'name' => $name,
                'phone' => $phone,
                'email' => $contactEmail,
                'origin_channel' => $channel,
                'origin_store_id' => $storeId,
                'registration_source' => mb_substr('whatsapp_'.$sourceType, 0, 40),
                'registered_at' => now(),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, 3);
    }

    /** @return list<int> */
    private function intList(mixed $value): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, is_array($value) ? $value : []),
            static fn (int $id): bool => $id > 0,
        )));
    }

    private function numeric(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function integerOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = filter_var($value, FILTER_VALIDATE_INT);

        return $value === false || $value < 0 ? null : (int) $value;
    }
}
