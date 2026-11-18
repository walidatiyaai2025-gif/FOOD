<?php

namespace App\Enterprise\Wms;

use App\Models\User;
use App\Services\OperationalTenantScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Administrator-configurable future legal-title *rules*, never a title certificate.
 *
 * A reviewed policy describes what evidence should be required. It cannot
 * certify an inventory position without an independently verified signed
 * contract and an immutable event-by-event title/provenance attestation.
 */
final class InventoryOwnershipRuleService
{
    public function __construct(private readonly OperationalTenantScope $stores) {}

    public function propose(User $actor, array $input): object
    {
        $data = Validator::make($input, [
            'store_id' => ['required', 'integer', 'min:1'],
            'channel' => ['required', 'in:B2B,B2C'],
            'supplier_id' => ['nullable', 'integer', 'min:1'],
            'warehouse_id' => ['nullable', 'integer', 'min:1'],
            'supplier_document_id' => ['nullable', 'integer', 'min:1'],
            'initial_owner_kind' => ['required', 'in:FOODEX,SUPPLIER,UNKNOWN'],
            'title_transfer_trigger' => ['required', 'in:NONE,RECEIPT,SALE,DISPATCH,PAYMENT,CONTRACT_EVENT'],
            'required_evidence_classes' => ['required', 'array', 'min:1', 'max:8'],
            'required_evidence_classes.*' => ['required', 'in:SIGNED_CONTRACT,CONSIGNMENT_AGREEMENT,TITLE_ADDENDUM,TRANSFER_CONFIRMATION'],
            // A policy maker must not nominate a weak generic permission (e.g.
            // inventory.view) as the legal-title checker authority.
            'checker_permission' => ['required', 'in:inventory_ownership_rules.review'],
            'effective_at' => ['required', 'date'],
            'request_uuid' => ['required', 'uuid'],
            'rule_uuid' => ['nullable', 'uuid'],
        ])->validate();

        $storeId = (int) $data['store_id'];
        $this->authorize($actor, $storeId, 'inventory_ownership_rules.manage');
        $companyId = $this->companyForStore($storeId);
        $this->assertChannel($storeId, $data['channel']);

        $supplierId = isset($data['supplier_id']) ? (int) $data['supplier_id'] : null;
        $warehouseId = isset($data['warehouse_id']) ? (int) $data['warehouse_id'] : null;
        $documentId = isset($data['supplier_document_id']) ? (int) $data['supplier_document_id'] : null;
        if ($data['initial_owner_kind'] === 'SUPPLIER' && $supplierId === null) {
            throw ValidationException::withMessages(['supplier_id' => ['A supplier-owned rule requires a scoped supplier.']]);
        }
        if ($supplierId !== null) {
            abort_unless($this->supplierInScope($supplierId, $companyId, $storeId), 404);
        }
        if ($warehouseId !== null) {
            abort_unless(DB::table('warehouses')->where('id', $warehouseId)
                ->where('store_id', $storeId)->where('is_active', true)->exists(), 404);
        }
        if ($documentId !== null) {
            abort_unless($supplierId !== null
                && DB::table('enterprise_supplier_documents')->where('id', $documentId)
                    ->where('supplier_id', $supplierId)
                    ->where(function ($scope): void {
                        $scope->whereNull('expires_at')
                            ->orWhereDate('expires_at', '>=', now()->toDateString());
                    })->exists(), 404);
        }
        if ($data['initial_owner_kind'] !== 'SUPPLIER' && $supplierId !== null) {
            throw ValidationException::withMessages(['supplier_id' => ['Supplier scope requires supplier-owned title intent.']]);
        }
        $evidenceClasses = array_values(array_unique($data['required_evidence_classes']));
        sort($evidenceClasses);
        $snapshot = [
            'company_id' => $companyId,
            'store_id' => $storeId,
            'channel' => $data['channel'],
            'supplier_id' => $supplierId,
            'warehouse_id' => $warehouseId,
            'supplier_document_id' => $documentId,
            'initial_owner_kind' => $data['initial_owner_kind'],
            'title_transfer_trigger' => $data['title_transfer_trigger'],
            'required_evidence_classes' => $evidenceClasses,
            'checker_permission' => $data['checker_permission'],
            'effective_at' => date('Y-m-d H:i:s', strtotime($data['effective_at'])),
        ];
        $sha = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
        $requestUuid = (string) $data['request_uuid'];
        $ruleUuid = (string) ($data['rule_uuid'] ?? Str::uuid());

        return DB::transaction(function () use ($actor, $snapshot, $sha, $requestUuid, $ruleUuid): object {
            $existing = DB::table('enterprise_inventory_ownership_rule_revisions')
                ->where('request_uuid', $requestUuid)->lockForUpdate()->first();
            if ($existing !== null) {
                if (! $this->revisionMatchesProposal($existing)
                    || $existing->payload_sha256 !== $sha || (int) $existing->proposed_by !== (int) $actor->id) {
                    throw ValidationException::withMessages(['request_uuid' => ['Conflicting policy request replay.']]);
                }

                return $existing;
            }

            // Replay identity is resolved before checking whether a new
            // proposal's effective date has since become stale. This keeps
            // idempotent retries stable while still rejecting retroactive
            // proposals with a new request UUID.
            if (strtotime($snapshot['effective_at']) < now()->startOfDay()->timestamp) {
                throw ValidationException::withMessages(['effective_at' => ['Retroactive ownership rules are not permitted.']]);
            }

            $latest = DB::table('enterprise_inventory_ownership_rule_revisions')
                ->where('rule_uuid', $ruleUuid)->orderByDesc('revision')->lockForUpdate()->first();
            if ($latest !== null && ((int) $latest->store_id !== $snapshot['store_id']
                || $latest->channel !== $snapshot['channel'])) {
                throw ValidationException::withMessages(['rule_uuid' => ['Policy revisions cannot move between stores or channels.']]);
            }

            $insert = [
                ...$snapshot,
                'required_evidence_classes' => json_encode($snapshot['required_evidence_classes'], JSON_THROW_ON_ERROR),
                'rule_uuid' => $ruleUuid,
                'revision' => ($latest === null ? 1 : (int) $latest->revision + 1),
                'status' => 'DRAFT',
                'request_uuid' => $requestUuid,
                'payload_sha256' => $sha,
                'proposed_by' => (int) $actor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            DB::table('enterprise_inventory_ownership_rule_revisions')->insertOrIgnore($insert);
            $created = DB::table('enterprise_inventory_ownership_rule_revisions')
                ->where('request_uuid', $requestUuid)->first();
            if ($created === null || $created->payload_sha256 !== $sha
                || (int) $created->proposed_by !== (int) $actor->id) {
                throw ValidationException::withMessages(['request_uuid' => ['Conflicting concurrent policy revision.']]);
            }

            return $created;
        });
    }

    public function review(User $checker, int $revisionId, bool $approve): object
    {
        return DB::transaction(function () use ($checker, $revisionId, $approve): object {
            $query = DB::table('enterprise_inventory_ownership_rule_revisions');
            $rule = $query->where('id', $revisionId)->lockForUpdate()->first();
            abort_if($rule === null, 404);
            $this->authorize($checker, (int) $rule->store_id, (string) $rule->checker_permission);
            // An approved policy cannot cross a later store legal-company or
            // channel reassignment even when its stored store_id is unchanged.
            abort_unless((int) $rule->company_id === $this->companyForStore((int) $rule->store_id), 409);
            $this->assertChannel((int) $rule->store_id, (string) $rule->channel);
            // A review must bind exactly the policy fields the maker proposed.
            // Changing a draft's checker/trigger/evidence after proposal cannot
            // be silently ratified by a later reviewer or a replay request.
            abort_unless($this->revisionMatchesProposal($rule), 409);
            abort_if((int) $rule->proposed_by === (int) $checker->id, 403);
            $target = $approve ? 'APPROVED_RULE' : 'REJECTED';
            // Idempotent approval replay must not claim success on a revoked
            // supplier, inactive warehouse or now-expired source document.
            if ($approve) {
                abort_unless($this->referencesStillValid($rule), 409);
            }
            if ($rule->status !== 'DRAFT') {
                abort_unless($rule->status === $target && (int) $rule->reviewed_by === (int) $checker->id, 409);

                return $rule;
            }
            if ($approve) {
                // Serialize approvals for a store before checking competing
                // approved policies; concurrent reviewers must not both win.
                DB::table('stores')->where('id', $rule->store_id)->lockForUpdate()->first();
                abort_if(strtotime((string) $rule->effective_at) < now()->startOfDay()->timestamp, 409);
                // Different policy identities may not occupy an identical
                // scope. Same identity uses monotonically increasing revisions.
                $conflict = DB::table('enterprise_inventory_ownership_rule_revisions')
                    ->where('status', 'APPROVED_RULE')
                    ->where('store_id', $rule->store_id)
                    ->where('channel', $rule->channel)
                    ->where('rule_uuid', '<>', $rule->rule_uuid);
                foreach (['supplier_id', 'warehouse_id', 'supplier_document_id'] as $field) {
                    $conflict = $rule->$field === null ? $conflict->whereNull($field) : $conflict->where($field, $rule->$field);
                }
                abort_if($conflict->exists(), 409);
            }
            DB::table('enterprise_inventory_ownership_rule_revisions')->where('id', $revisionId)->update([
                'status' => $target,
                'reviewed_by' => (int) $checker->id,
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);

            return DB::table('enterprise_inventory_ownership_rule_revisions')->where('id', $revisionId)->first();
        });
    }

    /**
     * Rules are *not* stock-title evidence. Return the proposed policy
     * separately and always deny authoritative certification from this source.
     *
     * @return array{state:string,certified:bool,policy:?object}
     */
    public function advisoryFor(User $actor, int $storeId, string $channel, ?int $supplierId, ?int $warehouseId): array
    {
        $this->authorize($actor, $storeId, 'inventory.view');
        // Do not issue even non-certifying guidance for a foreign, deleted or
        // disabled warehouse. The client cannot designate an arbitrary store's
        // warehouse as the context for this store's title policy.
        if ($warehouseId !== null) {
            abort_unless(DB::table('warehouses')->where('id', $warehouseId)
                ->where('store_id', $storeId)->where('is_active', true)->exists(), 404);
        }

        // A store's legal entity can change after a rule was approved.
        // Never serve a previous company's policy as current title guidance.
        $this->assertChannel($storeId, $channel);
        $companyId = $this->companyForStore($storeId);
        // A client-supplied supplier context is not an authority to read the
        // generic fallback policy of a different supplier or legal store.
        // Reject stale/revoked/foreign supplier identities before selection.
        if ($supplierId !== null) {
            abort_unless($this->supplierInScope($supplierId, $companyId, $storeId), 404);
        }
        $rules = DB::table('enterprise_inventory_ownership_rule_revisions')
            ->where('company_id', $companyId)
            ->where('store_id', $storeId)->where('channel', $channel)
            ->where('status', 'APPROVED_RULE')
            ->where('effective_at', '<=', now())
            ->where(function ($q) use ($supplierId): void {
                $q->whereNull('supplier_id');
                if ($supplierId !== null) {
                    $q->orWhere('supplier_id', $supplierId);
                }
            })->where(function ($q) use ($warehouseId): void {
                $q->whereNull('warehouse_id');
                if ($warehouseId !== null) {
                    $q->orWhere('warehouse_id', $warehouseId);
                }
            })->orderByDesc('revision')->limit(100)->get();

        $ranked = $rules->map(fn ($r) => [
            'rule' => $r,
            'specificity' => ($r->supplier_id !== null ? 2 : 0) + ($r->warehouse_id !== null ? 1 : 0),
        ])->sortByDesc('specificity')->values();
        $first = $ranked->first();
        // A previously approved rule is not authoritative advice after its
        // supplier, warehouse or source-document scope is revoked. Fail closed
        // rather than silently falling back to a less specific policy: the
        // checker must re-evaluate the explicit business scope first.
        if ($first !== null && (! $this->revisionMatchesProposal($first['rule'])
            || ! $this->referencesStillValid($first['rule']))) {
            return ['state' => 'UNKNOWN', 'certified' => false, 'policy' => null];
        }

        $ambiguous = $first !== null && $ranked->count() > 1
            && $ranked[1]['specificity'] === $first['specificity']
            && $ranked[1]['rule']->rule_uuid !== $first['rule']->rule_uuid;

        return ['state' => $ambiguous ? 'CONFLICT' : 'UNKNOWN', 'certified' => false,
            'policy' => $ambiguous ? null : ($first['rule'] ?? null)];
    }

    /**
     * A revision must still match the exact original proposal fingerprint.
     * This detects drift to a stored policy; SHA-256 is NOT a trusted issuer
     * signature and cannot certify or transfer stock title.
     */
    private function revisionMatchesProposal(object $rule): bool
    {
        try {
            $evidence = json_decode((string) $rule->required_evidence_classes, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($evidence) || ! array_is_list($evidence)
                || $evidence === [] || count($evidence) > 8) {
                return false;
            }

            foreach ($evidence as $class) {
                if (! in_array($class, [
                    'SIGNED_CONTRACT', 'CONSIGNMENT_AGREEMENT',
                    'TITLE_ADDENDUM', 'TRANSFER_CONFIRMATION',
                ], true)) {
                    return false;
                }
            }

            $effective = strtotime((string) $rule->effective_at);
            if ($effective === false) {
                return false;
            }

            $snapshot = [
                'company_id' => (int) $rule->company_id,
                'store_id' => (int) $rule->store_id,
                'channel' => (string) $rule->channel,
                'supplier_id' => $rule->supplier_id === null ? null : (int) $rule->supplier_id,
                'warehouse_id' => $rule->warehouse_id === null ? null : (int) $rule->warehouse_id,
                'supplier_document_id' => $rule->supplier_document_id === null ? null : (int) $rule->supplier_document_id,
                'initial_owner_kind' => (string) $rule->initial_owner_kind,
                'title_transfer_trigger' => (string) $rule->title_transfer_trigger,
                'required_evidence_classes' => $evidence,
                'checker_permission' => (string) $rule->checker_permission,
                'effective_at' => date('Y-m-d H:i:s', $effective),
            ];

            $recorded = (string) $rule->payload_sha256;

            return preg_match('/\A[a-f0-9]{64}\z/D', $recorded) === 1
                && hash_equals($recorded, hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)));
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Re-check mutable references after the DRAFT was created and before any
     * approved guidance is surfaced. This is a policy-scope check, never
     * evidence of legal title, stock valuation or an ownership transfer.
     */
    private function referencesStillValid(object $rule): bool
    {
        $storeId = (int) $rule->store_id;
        $supplierId = $rule->supplier_id === null ? null : (int) $rule->supplier_id;
        $warehouseId = $rule->warehouse_id === null ? null : (int) $rule->warehouse_id;
        $documentId = $rule->supplier_document_id === null ? null : (int) $rule->supplier_document_id;

        if (((string) $rule->initial_owner_kind === 'SUPPLIER') !== ($supplierId !== null)) {
            return false;
        }

        if ($supplierId !== null && ! $this->supplierInScope(
            $supplierId, (int) $rule->company_id, $storeId,
        )) {
            return false;
        }

        if ($warehouseId !== null && ! DB::table('warehouses')
            ->where('id', $warehouseId)->where('store_id', $storeId)
            ->where('is_active', true)->exists()) {
            return false;
        }

        if ($documentId !== null && ($supplierId === null
            || ! DB::table('enterprise_supplier_documents')->where('id', $documentId)
                ->where('supplier_id', $supplierId)
                ->where(function ($scope): void {
                    $scope->whereNull('expires_at')
                        ->orWhereDate('expires_at', '>=', now()->toDateString());
                })->exists())) {
            return false;
        }

        return true;
    }

    /**
     * A legal-company supplier can be company-wide, or explicitly scoped to
     * this store. Retail/Wholesale identity and activity are never inferred
     * from an arbitrary supplier_id supplied by the caller.
     */
    private function supplierInScope(int $supplierId, int $companyId, int $storeId): bool
    {
        return DB::table('enterprise_suppliers')
            ->where('id', $supplierId)
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->where(function ($scope) use ($storeId): void {
                $scope->where(function ($company): void {
                    $company->where('scope_type', 'company')->whereNull('store_id');
                })->orWhere(function ($store) use ($storeId): void {
                    $store->where('scope_type', 'store')->where('store_id', $storeId);
                });
            })->exists();
    }

    private function authorize(User $actor, int $storeId, string $permission): void
    {
        abort_unless($actor->hasRole('SUPER_ADMIN') || $actor->hasPermission($permission, $storeId), 403);
        $this->stores->assertStore($actor, $storeId, 'inventory.view');
    }

    private function companyForStore(int $storeId): int
    {
        $companyId = DB::table('enterprise_store_scopes as scope')
            ->join('enterprise_business_units as unit', 'unit.id', '=', 'scope.business_unit_id')
            ->join('enterprise_legal_entities as entity', 'entity.id', '=', 'unit.legal_entity_id')
            ->where('scope.store_id', $storeId)->value('entity.company_id');
        abort_if($companyId === null, 422);

        return (int) $companyId;
    }

    private function assertChannel(int $storeId, string $channel): void
    {
        $storeType = strtoupper((string) DB::table('stores as store')
            ->join('store_types as kind', 'kind.id', '=', 'store.store_type_id')
            ->where('store.id', $storeId)->value('kind.code'));
        abort_unless(match ($channel) {
            'B2B' => in_array($storeType, ['WHOLESALE', 'B2B'], true),
            'B2C' => in_array($storeType, ['RETAIL', 'B2C'], true),
            default => false,
        }, 422);
    }
}
