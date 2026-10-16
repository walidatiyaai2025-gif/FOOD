<?php

namespace App\Enterprise\Finance;

use App\Enterprise\Events\VersionedDomainEvent;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Enterprise\Outbox\OutboxConsumer;
use App\Models\EnterpriseFinanceAccount;
use App\Models\EnterpriseFinanceSourcePosting;
use App\Models\User;
use App\Support\EnterpriseDataScope;
use App\Support\ResolvedTenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

final class FinanceSourcePostingConsumer implements OutboxConsumer
{
    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly OrganizationHierarchyService $organizations,
        private readonly GeneralLedgerService $ledger,
    ) {}

    public function handle(VersionedDomainEvent $event): void
    {
        $mapping = $this->mapping($event);

        if (($mapping['mode'] ?? null) === 'ignore') {
            return;
        }

        $module = $this->modules->registration(FinanceFoundationService::MODULE);
        if ($module === null || $module->state !== ModuleState::ENABLED) {
            return;
        }

        $stateChangedAt = $module->getRawOriginal('state_changed_at');
        if (! is_string($stateChangedAt) || trim($stateChangedAt) === '') {
            throw new LogicException('Enabled Finance module has no effective state timestamp.');
        }

        $effectiveAt = CarbonImmutable::parse($stateChangedAt, 'UTC');
        if ($event->occurredAt->lt($effectiveAt)) {
            return;
        }

        $scope = $this->scope($event);
        $currency = $this->currency($event, $mapping);
        [$maker, $poster] = $this->actors();
        $channel = $this->channel($scope->storeId);
        $makerContext = $this->context($maker, $scope, $channel);
        $posterContext = $this->context($poster, $scope, $channel);

        $sourceType = $this->requiredString($mapping['source_type'] ?? null, 'source_type');
        $sourceId = $this->positiveInteger(
            data_get($event->payload, $this->requiredString($mapping['source_id_path'] ?? null, 'source_id_path')),
            'source_id',
        );
        $sourceReference = $this->nullableString(data_get(
            $event->payload,
            $this->requiredString($mapping['source_reference_path'] ?? null, 'source_reference_path'),
        ));
        $amount = $this->requiredString(data_get(
            $event->payload,
            $this->requiredString($mapping['amount_path'] ?? null, 'amount_path'),
        ), 'amount');
        $entryDate = CarbonImmutable::parse($this->requiredString(data_get(
            $event->payload,
            $this->requiredString($mapping['entry_date_path'] ?? null, 'entry_date_path'),
        ), 'entry_date'))->toDateString();

        $debit = $this->account(
            $scope,
            $this->requiredString($mapping['debit_account_code'] ?? null, 'debit_account_code'),
        );
        $credit = $this->account(
            $scope,
            $this->requiredString($mapping['credit_account_code'] ?? null, 'credit_account_code'),
        );

        if ($debit->id === $credit->id) {
            throw new LogicException('Finance source posting debit and credit accounts must differ.');
        }

        DB::transaction(function () use (
            $event,
            $mapping,
            $scope,
            $currency,
            $maker,
            $poster,
            $makerContext,
            $posterContext,
            $sourceType,
            $sourceId,
            $sourceReference,
            $amount,
            $entryDate,
            $debit,
            $credit,
        ): void {
            $existing = EnterpriseFinanceSourcePosting::query()
                ->where('event_id', $event->id)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof EnterpriseFinanceSourcePosting) {
                return;
            }

            $description = sprintf(
                '%s %s.v%d%s',
                strtoupper((string) $mapping['domain']),
                $event->type,
                $event->version,
                $sourceReference === null ? '' : ' '.$sourceReference,
            );

            $journal = $this->ledger->createDraft(
                $maker,
                $makerContext,
                [
                    'idempotency_key' => 'finance-source-'.$event->id,
                    'entry_date' => $entryDate,
                    'description' => mb_substr($description, 0, 500),
                    'lines' => [
                        [
                            'account_id' => $debit->id,
                            'debit' => $amount,
                            'credit' => '0',
                            'memo' => $sourceReference,
                        ],
                        [
                            'account_id' => $credit->id,
                            'debit' => '0',
                            'credit' => $amount,
                            'memo' => $sourceReference,
                        ],
                    ],
                ],
            );

            $posted = $this->ledger->post(
                $poster,
                $posterContext,
                $journal->id,
                new FinancePostingApprovalContext(
                    action: FinanceApprovalService::ACTION_GL_POST,
                    entityType: 'enterprise_finance_journal',
                    entityId: (string) $journal->id,
                    makerId: (int) $maker->id,
                    amount: $amount,
                    currencyCode: $currency,
                ),
            );

            EnterpriseFinanceSourcePosting::query()->create([
                'event_id' => $event->id,
                'event_type' => $event->type,
                'event_version' => $event->version,
                'source_domain' => (string) $mapping['domain'],
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'source_reference' => $sourceReference,
                'organization_id' => $scope->organizationId,
                'company_id' => $scope->companyId,
                'legal_entity_id' => $scope->legalEntityId,
                'store_id' => $scope->storeId,
                'currency_code' => $currency,
                'journal_id' => $posted->id,
                'occurred_at' => $event->occurredAt,
                'posted_at' => $posted->posted_at ?? now(),
            ]);
        }, attempts: 3);
    }

    /** @return array<string, mixed> */
    private function mapping(VersionedDomainEvent $event): array
    {
        $mapping = config('enterprise_finance_integrations.events.'.$event->type);
        if (! is_array($mapping)) {
            throw new LogicException("No Finance source posting mapping exists for [{$event->type}].");
        }

        if ((int) ($mapping['version'] ?? 0) !== $event->version) {
            throw new LogicException("Unsupported Finance source posting contract [{$event->type}.v{$event->version}].");
        }

        $domain = $this->requiredString($mapping['domain'] ?? null, 'domain');
        $domains = array_values((array) config('enterprise_finance_integrations.supported_domains', []));
        if (! in_array($domain, $domains, true)) {
            throw new LogicException("Unsupported Finance source domain [{$domain}].");
        }

        $mode = $mapping['mode'] ?? null;
        if (! in_array($mode, ['post', 'ignore'], true)) {
            throw new LogicException("Finance source posting mode for [{$event->type}] is invalid.");
        }

        return $mapping;
    }

    /** @param array<string, mixed> $mapping */
    private function currency(VersionedDomainEvent $event, array $mapping): string
    {
        $path = $this->requiredString($mapping['currency_path'] ?? null, 'currency_path');
        $currency = strtoupper($this->requiredString(data_get($event->payload, $path), 'currency_code'));
        $configured = strtoupper(trim((string) config('enterprise_finance_integrations.currency_code', '')));

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1 || preg_match('/^[A-Z]{3}$/', $configured) !== 1) {
            throw new LogicException('Finance source posting currency configuration is invalid.');
        }

        if ($currency !== $configured) {
            throw new LogicException("Finance source posting currency [{$currency}] does not match configured ledger currency [{$configured}].");
        }

        return $currency;
    }

    private function scope(VersionedDomainEvent $event): EnterpriseDataScope
    {
        $storeId = $this->positiveInteger($event->scope['store_id'] ?? null, 'scope.store_id');
        $hierarchy = $this->organizations->scopeForStore($storeId);
        if ($hierarchy === null) {
            throw new LogicException("Store [{$storeId}] has no Enterprise hierarchy scope.");
        }

        foreach ([
            'organization_id' => 'organization_id',
            'company_id' => 'company_id',
            'legal_entity_id' => 'legal_entity_id',
        ] as $eventKey => $hierarchyKey) {
            if (! array_key_exists($eventKey, $event->scope) || $event->scope[$eventKey] === null) {
                continue;
            }

            if ($this->positiveInteger($event->scope[$eventKey], 'scope.'.$eventKey) !== $hierarchy[$hierarchyKey]) {
                throw new LogicException("Finance source event scope mismatch for [{$eventKey}].");
            }
        }

        return EnterpriseDataScope::fromHierarchy($hierarchy);
    }

    /** @return array{User, User} */
    private function actors(): array
    {
        $makerId = $this->positiveInteger(
            config('enterprise_finance_integrations.maker_user_id'),
            'maker_user_id',
        );
        $posterId = $this->positiveInteger(
            config('enterprise_finance_integrations.poster_user_id'),
            'poster_user_id',
        );

        if ($makerId === $posterId) {
            throw new LogicException('Finance source posting maker and poster must be different users.');
        }

        $maker = User::query()->whereKey($makerId)->where('is_active', true)->first();
        $poster = User::query()->whereKey($posterId)->where('is_active', true)->first();
        if (! $maker instanceof User || ! $poster instanceof User) {
            throw new LogicException('Finance source posting maker/poster users must both be active.');
        }

        return [$maker, $poster];
    }

    private function context(User $actor, EnterpriseDataScope $scope, string $channel): ResolvedTenantContext
    {
        return new ResolvedTenantContext(
            userId: (int) $actor->id,
            channel: $channel,
            storeId: $scope->storeId,
            enterpriseScope: $scope,
        );
    }

    private function channel(int $storeId): string
    {
        $value = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('stores.id', $storeId)
            ->where('stores.is_active', true)
            ->value('store_types.code');

        $channel = is_string($value) ? strtolower($value) : '';
        if (! in_array($channel, ['b2b', 'b2c'], true)) {
            throw new LogicException("Store [{$storeId}] has no supported commercial channel.");
        }

        return $channel;
    }

    private function account(EnterpriseDataScope $scope, string $code): EnterpriseFinanceAccount
    {
        $account = EnterpriseFinanceAccount::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId)
            ->where('code', strtoupper(trim($code)))
            ->first();

        if (! $account instanceof EnterpriseFinanceAccount) {
            throw new LogicException("Finance source posting account [{$code}] is unavailable in the legal entity.");
        }

        return $account;
    }

    private function positiveInteger(mixed $value, string $field): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            throw new LogicException("Finance source posting [{$field}] must be a positive integer.");
        }

        return (int) $value;
    }

    private function requiredString(mixed $value, string $field): string
    {
        if (! is_scalar($value)) {
            throw new LogicException("Finance source posting [{$field}] is required.");
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            throw new LogicException("Finance source posting [{$field}] is required.");
        }

        return $normalized;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : mb_substr($normalized, 0, 128);
    }
}
