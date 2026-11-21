<?php

namespace App\Enterprise\Finance;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementResolver;
use App\Models\CollectionAccount;
use App\Models\EnterpriseFinanceAccount;
use App\Models\EnterpriseFinanceCollectionAccountMapping;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\EnterpriseDataScope;
use App\Support\EnterpriseScopeAuthorizer;
use App\Support\ResolvedTenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class CollectionAccountSettlementMappingService
{
    public function __construct(
        private readonly EnterpriseScopeAuthorizer $authorizer,
        private readonly ModuleEntitlementResolver $entitlements,
        private readonly FinanceWorkbenchAccess $workbenchAccess,
        private readonly AuditLogger $audit,
    ) {}

    /** @return Collection<int, EnterpriseFinanceCollectionAccountMapping> */
    public function revisions(User $actor, ResolvedTenantContext $context): Collection
    {
        $scope = $this->authorizedScope($actor, $context, 'finance.view', 'finance.ar.read');

        return EnterpriseFinanceCollectionAccountMapping::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId)
            ->where('store_id', $context->requiresStore())
            ->orderByDesc('id')
            ->limit(50)
            ->get();
    }

    /**
     * Validate the existing collection-to-clearing-account form without
     * persisting a proposal, audit event, payment or journal. This is only a
     * current preflight: Save takes its own locks and revalidates every rule.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function testProposal(User $actor, ResolvedTenantContext $context, array $attributes): void
    {
        $scope = $this->authorizedScope($actor, $context, 'finance.manage', 'finance.ar.manage');
        $data = $this->validatedProposalInput($attributes);
        $storeId = $context->requiresStore();
        $collectionId = (int) $data['collection_account_id'];
        $financeId = (int) $data['finance_account_id'];

        $collection = CollectionAccount::query()
            ->whereKey($collectionId)
            ->where('store_id', $storeId)
            ->firstOrFail();
        $this->assertCollectionAccount($collection, $context);
        $this->assertFinanceAccount($scope, $financeId);

        // Preview also refuses a request UUID belonging to another maker or
        // different canonical mapping. Save repeats this under row locks.
        $previous = EnterpriseFinanceCollectionAccountMapping::query()
            ->where('request_id', $data['request_id'])
            ->first();
        if ($previous !== null) {
            $canonical = [
                'collection_account_id' => $collectionId,
                'finance_account_id' => $financeId,
                'organization_id' => $scope->organizationId,
                'company_id' => $scope->companyId,
                'legal_entity_id' => $scope->legalEntityId,
                'store_id' => $storeId,
                'currency_code' => strtoupper((string) $collection->currency),
            ];
            $digest = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
            if ((int) $previous->proposed_by !== (int) $actor->id
                || ! hash_equals((string) $previous->content_sha256, $digest)) {
                throw ValidationException::withMessages([
                    'request_id' => [__('finance_workbench.receivables.mapping_request_conflict')],
                ]);
            }
        }
    }

    /**
     * A proposal is a mapping configuration, not proof of cash, cleared
     * custody, an AR invoice posting, or TreasuryReceiptPosted lineage.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function propose(User $actor, ResolvedTenantContext $context, array $attributes): EnterpriseFinanceCollectionAccountMapping
    {
        $scope = $this->authorizedScope($actor, $context, 'finance.manage', 'finance.ar.manage');

        $data = $this->validatedProposalInput($attributes);
        $storeId = $context->requiresStore();
        $collectionId = (int) $data['collection_account_id'];
        $financeId = (int) $data['finance_account_id'];

        return DB::transaction(function () use ($actor, $context, $scope, $storeId, $collectionId, $financeId, $data): EnterpriseFinanceCollectionAccountMapping {
            // A stable collection-account row serializes revision creation and
            // approval, including when no previous mapping exists.
            $collection = CollectionAccount::query()
                ->whereKey($collectionId)
                ->where('store_id', $storeId)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertCollectionAccount($collection, $context);
            $this->assertFinanceAccount($scope, $financeId);

            $canonical = [
                'collection_account_id' => $collectionId,
                'finance_account_id' => $financeId,
                'organization_id' => $scope->organizationId,
                'company_id' => $scope->companyId,
                'legal_entity_id' => $scope->legalEntityId,
                'store_id' => $storeId,
                'currency_code' => strtoupper((string) $collection->currency),
            ];
            $digest = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));

            $previous = EnterpriseFinanceCollectionAccountMapping::query()
                ->where('request_id', $data['request_id'])
                ->lockForUpdate()
                ->first();
            if ($previous instanceof EnterpriseFinanceCollectionAccountMapping) {
                if ((int) $previous->proposed_by !== (int) $actor->id
                    || ! hash_equals((string) $previous->content_sha256, $digest)) {
                    throw ValidationException::withMessages([
                        'request_id' => [__('finance_workbench.receivables.mapping_request_conflict')],
                    ]);
                }

                return $previous;
            }

            $latest = EnterpriseFinanceCollectionAccountMapping::query()
                ->where('collection_account_id', $collectionId)
                ->orderByDesc('revision')
                ->lockForUpdate()
                ->first();

            $mapping = EnterpriseFinanceCollectionAccountMapping::query()->create([
                ...$canonical,
                'revision' => $latest === null ? 1 : ((int) $latest->revision) + 1,
                'status' => 'PROPOSED',
                'request_id' => (string) $data['request_id'],
                'content_sha256' => $digest,
                'proposed_by' => $actor->id,
            ]);

            $this->audit->record(
                'enterprise.finance.collection_account_mapping_proposed',
                $actor, $mapping, null, $mapping->toArray(),
            );

            return $mapping;
        }, 3);
    }

    /**
     * Independent Finance approval may supersede an earlier approved account,
     * but cannot attest the receipt-to-Treasury journal source.
     */
    public function review(
        User $actor,
        ResolvedTenantContext $context,
        int $mappingId,
        string $decision,
    ): EnterpriseFinanceCollectionAccountMapping {
        $scope = $this->authorizedScope($actor, $context, 'finance.approve', 'finance.approvals.approve');
        $decision = Validator::make(['decision' => $decision], [
            'decision' => ['required', 'in:approve,reject'],
        ])->validate()['decision'];

        // Resolve identity without lock; the stable parent account must be
        // locked BEFORE the mapping row, matching proposal lock order.
        $identity = EnterpriseFinanceCollectionAccountMapping::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId)
            ->where('store_id', $context->requiresStore())
            ->whereKey($mappingId)
            ->firstOrFail(['collection_account_id']);

        return DB::transaction(function () use ($actor, $context, $scope, $mappingId, $identity, $decision): EnterpriseFinanceCollectionAccountMapping {
            $collection = CollectionAccount::query()
                ->whereKey($identity->collection_account_id)
                ->where('store_id', $context->requiresStore())
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertCollectionAccount($collection, $context);

            $mapping = EnterpriseFinanceCollectionAccountMapping::query()
                ->where('collection_account_id', $collection->id)
                ->where('organization_id', $scope->organizationId)
                ->where('company_id', $scope->companyId)
                ->where('legal_entity_id', $scope->legalEntityId)
                ->where('store_id', $context->requiresStore())
                ->whereKey($mappingId)
                ->lockForUpdate()
                ->firstOrFail();

            abort_if((int) $mapping->proposed_by === (int) $actor->id, 403);

            $newStatus = $decision === 'approve' ? 'APPROVED' : 'REJECTED';
            if ($mapping->status === $newStatus && (int) $mapping->reviewed_by === (int) $actor->id) {
                // An idempotent retry must not recertify a stale clearing
                // account after its posting authority has been revoked.
                // Keep the immutable historical decision and audit unchanged.
                if ($newStatus === 'APPROVED') {
                    $this->assertFinanceAccount($scope, (int) $mapping->finance_account_id);
                }

                return $mapping;
            }
            if ($mapping->status !== 'PROPOSED') {
                throw ValidationException::withMessages([
                    'mapping_id' => [__('finance_workbench.receivables.mapping_already_reviewed')],
                ]);
            }

            // Approval must revalidate the original immutable account/currency
            // identity, not just the disabled/changed candidate shown in UI.
            if (strtoupper((string) $collection->currency) !== (string) $mapping->currency_code) {
                throw ValidationException::withMessages([
                    'mapping_id' => [__('finance_workbench.receivables.mapping_account_invalid')],
                ]);
            }
            if ($newStatus === 'APPROVED') {
                // The locked collection-account row serializes approval order.
                // A late checker must not reactivate an older proposal after
                // a newer revision has been approved, even if that newer
                // revision was later superseded by another approved revision.
                $newerApprovedRevisionExists = EnterpriseFinanceCollectionAccountMapping::query()
                    ->where('collection_account_id', $collection->id)
                    ->where('organization_id', $scope->organizationId)
                    ->where('company_id', $scope->companyId)
                    ->where('legal_entity_id', $scope->legalEntityId)
                    ->where('store_id', $context->requiresStore())
                    ->where('revision', '>', (int) $mapping->revision)
                    ->whereIn('status', ['APPROVED', 'SUPERSEDED'])
                    ->exists();
                if ($newerApprovedRevisionExists) {
                    throw ValidationException::withMessages([
                        'mapping_id' => [__('finance_workbench.receivables.mapping_already_reviewed')],
                    ]);
                }

                $this->assertFinanceAccount($scope, (int) $mapping->finance_account_id);

                // Superseding an approved revision is itself a financial-control
                // transition. Persist one immutable before/after audit event per
                // revision instead of silently bulk-updating historical authority.
                $previousApproved = EnterpriseFinanceCollectionAccountMapping::query()
                    ->where('collection_account_id', $collection->id)
                    ->where('organization_id', $scope->organizationId)
                    ->where('company_id', $scope->companyId)
                    ->where('legal_entity_id', $scope->legalEntityId)
                    ->where('store_id', $context->requiresStore())
                    ->where('status', 'APPROVED')
                    ->lockForUpdate()
                    ->get();

                foreach ($previousApproved as $previousMapping) {
                    $previousState = $previousMapping->toArray();
                    $previousMapping->forceFill(['status' => 'SUPERSEDED'])->save();

                    $this->audit->record(
                        'enterprise.finance.collection_account_mapping_superseded',
                        $actor,
                        $previousMapping,
                        $previousState,
                        $previousMapping->refresh()->toArray(),
                    );
                }
            }

            $before = $mapping->toArray();
            $mapping->forceFill([
                'status' => $newStatus,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
            ])->save();

            $this->audit->record(
                'enterprise.finance.collection_account_mapping_reviewed',
                $actor, $mapping, $before, $mapping->fresh()->toArray(),
            );

            return $mapping->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function validatedProposalInput(array $attributes): array
    {
        return Validator::make($attributes, [
            'request_id' => ['required', 'uuid'],
            'collection_account_id' => ['required', 'integer', 'min:1'],
            'finance_account_id' => ['required', 'integer', 'min:1'],
        ])->validate();
    }

    private function authorizedScope(
        User $actor,
        ResolvedTenantContext $context,
        string $permission,
        string $capability,
    ): EnterpriseDataScope {
        abort_if($context->enterpriseScope === null, 409, 'Enterprise data scope is required.');
        $scope = $context->enterpriseScope;
        $storeId = $context->requiresStore();
        $this->authorizer->authorize($actor, $context, $permission, 'legal_entity', $scope->legalEntityId);
        abort_unless($this->workbenchAccess->canPerform($actor, $storeId, $permission, $capability), 403);
        abort_unless($this->entitlements->allows(
            FinanceFoundationService::MODULE,
            new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $scope->legalEntityId),
            $capability,
        ), 403);

        return $scope;
    }

    private function assertCollectionAccount(CollectionAccount $collection, ResolvedTenantContext $context): void
    {
        $expectedActor = $context->isRetail() ? 'driver' : 'van_rep';
        if ((string) $collection->status !== 'active'
            || (string) $collection->actor_type !== $expectedActor
            || preg_match('/^[A-Z]{3}$/', strtoupper((string) $collection->currency)) !== 1) {
            throw ValidationException::withMessages([
                'collection_account_id' => [__('finance_workbench.receivables.mapping_account_invalid')],
            ]);
        }
    }

    private function assertFinanceAccount(EnterpriseDataScope $scope, int $financeId): void
    {
        $valid = EnterpriseFinanceAccount::query()
            ->whereKey($financeId)
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId)
            ->where('status', 'active')
            ->where('type', 'asset')
            ->where('normal_balance', 'debit')
            ->where('allow_posting', true)
            ->where('is_control', false)
            ->exists();

        if (! $valid) {
            throw ValidationException::withMessages([
                'finance_account_id' => [__('finance_workbench.receivables.mapping_account_invalid')],
            ]);
        }
    }
}
