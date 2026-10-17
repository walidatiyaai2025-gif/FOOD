<?php

namespace App\Enterprise\Procurement;

use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\EnterpriseSupplier;
use App\Models\EnterpriseSupplierContact;
use App\Models\EnterpriseSupplierDocument;
use App\Models\EnterpriseSupplierPortalToken;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\EnterpriseDataScope;
use App\Support\EnterpriseScopeAuthorizer;
use App\Support\ResolvedTenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SupplierCollaborationManager
{
    private const VIEW_PERMISSION = 'procurement.suppliers.view';

    private const MANAGE_PERMISSION = 'procurement.suppliers.manage';

    private const PORTAL_CAPABILITIES = ['rfq', 'documents'];

    public function __construct(
        private readonly EnterpriseScopeAuthorizer $authorizer,
        private readonly ModuleRegistry $modules,
        private readonly OrganizationHierarchyService $hierarchy,
        private readonly ProcurementSourcingManager $sourcing,
        private readonly AuditLogger $audit,
    ) {}

    public function addContact(
        User $actor,
        ResolvedTenantContext $context,
        int $supplierId,
        array $attributes,
    ): EnterpriseSupplierContact {
        $scope = $this->authorizedScope($actor, $context, self::MANAGE_PERMISSION);
        $supplier = $this->visibleSupplier($scope, $supplierId);
        $data = Validator::make($attributes, [
            'name' => ['required', 'string', 'max:190'],
            'role' => ['nullable', 'string', 'max:96'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:64'],
            'is_primary' => ['nullable', 'boolean'],
        ])->validate();

        return DB::transaction(function () use ($actor, $supplier, $data): EnterpriseSupplierContact {
            if (($data['is_primary'] ?? false) === true) {
                EnterpriseSupplierContact::query()
                    ->where('supplier_id', $supplier->id)
                    ->update(['is_primary' => false, 'updated_at' => now()]);
            }

            $contact = EnterpriseSupplierContact::query()->create([
                'supplier_id' => $supplier->id,
                'name' => trim((string) $data['name']),
                'role' => $data['role'] ?? null,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'is_primary' => (bool) ($data['is_primary'] ?? false),
                'is_active' => true,
                'created_by' => $actor->id,
            ]);

            $this->audit->record(
                'enterprise.supplier.contact_created',
                $actor,
                $supplier,
                null,
                ['supplier_id' => $supplier->id, 'contact_id' => $contact->id, ...$contact->toArray()],
            );

            return $contact->refresh();
        }, 3);
    }

    public function updateQualification(
        User $actor,
        ResolvedTenantContext $context,
        int $supplierId,
        array $attributes,
    ): EnterpriseSupplier {
        $scope = $this->authorizedScope($actor, $context, self::MANAGE_PERMISSION);
        $supplier = $this->visibleSupplier($scope, $supplierId);
        $data = Validator::make($attributes, [
            'qualification_status' => ['required', Rule::in(['unqualified', 'pending', 'qualified', 'suspended'])],
            'default_lead_time_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'performance_tier' => ['nullable', Rule::in(['preferred', 'standard', 'watch'])],
        ])->validate();

        $before = $supplier->toArray();
        $supplier->forceFill([
            'qualification_status' => $data['qualification_status'],
            'default_lead_time_days' => $data['default_lead_time_days'] ?? null,
            'performance_tier' => $data['performance_tier'] ?? null,
            'updated_by' => $actor->id,
        ])->save();

        $this->audit->record(
            'enterprise.supplier.qualification_updated',
            $actor,
            $supplier,
            $before,
            $supplier->fresh()->toArray(),
        );

        return $supplier->refresh();
    }

    public function setBlocked(
        User $actor,
        ResolvedTenantContext $context,
        int $supplierId,
        bool $blocked,
        ?string $reason = null,
    ): EnterpriseSupplier {
        $scope = $this->authorizedScope($actor, $context, self::MANAGE_PERMISSION);
        $supplier = $this->visibleSupplier($scope, $supplierId);
        $validated = Validator::make(
            ['blocked' => $blocked, 'reason' => $reason],
            [
                'blocked' => ['required', 'boolean'],
                'reason' => [$blocked ? 'required' : 'nullable', 'string', 'max:2000'],
            ],
        )->validate();

        $before = $supplier->toArray();
        $supplier->forceFill([
            'status' => $blocked ? 'on_hold' : 'active',
            'blocked_reason' => $blocked ? $validated['reason'] : null,
            'blocked_at' => $blocked ? now() : null,
            'blocked_by' => $blocked ? $actor->id : null,
            'updated_by' => $actor->id,
        ])->save();

        $this->audit->record(
            $blocked ? 'enterprise.supplier.blocked' : 'enterprise.supplier.unblocked',
            $actor,
            $supplier,
            $before,
            $supplier->fresh()->toArray(),
        );

        return $supplier->refresh();
    }

    /** @return array{token:EnterpriseSupplierPortalToken,plain_token:string} */
    public function issuePortalToken(
        User $actor,
        ResolvedTenantContext $context,
        int $supplierId,
        array $attributes,
    ): array {
        $scope = $this->authorizedScope($actor, $context, self::MANAGE_PERMISSION);
        $supplier = $this->visibleSupplier($scope, $supplierId);
        $data = Validator::make($attributes, [
            'expires_at' => ['required', 'date', 'after:now'],
            'capabilities' => ['required', 'array', 'min:1'],
            'capabilities.*' => [Rule::in(self::PORTAL_CAPABILITIES)],
        ])->validate();

        $plain = Str::random(64);
        $token = EnterpriseSupplierPortalToken::query()->create([
            'supplier_id' => $supplier->id,
            'token_hash' => hash('sha256', $plain),
            'capabilities' => array_values(array_unique($data['capabilities'])),
            'expires_at' => $data['expires_at'],
            'created_by' => $actor->id,
        ]);

        $this->audit->record(
            'enterprise.supplier.portal_token_issued',
            $actor,
            $supplier,
            null,
            [
                'supplier_id' => $supplier->id,
                'portal_token_id' => $token->id,
                'expires_at' => $token->expires_at?->toIso8601String(),
                'capabilities' => $token->capabilities,
            ],
        );

        return ['token' => $token->refresh(), 'plain_token' => $plain];
    }

    public function revokePortalToken(
        User $actor,
        ResolvedTenantContext $context,
        int $supplierId,
        int $tokenId,
    ): void {
        $scope = $this->authorizedScope($actor, $context, self::MANAGE_PERMISSION);
        $supplier = $this->visibleSupplier($scope, $supplierId);
        $token = EnterpriseSupplierPortalToken::query()
            ->where('supplier_id', $supplier->id)
            ->whereKey($tokenId)
            ->firstOrFail();

        if ($token->revoked_at === null) {
            $token->forceFill(['revoked_at' => now()])->save();
            $this->audit->record(
                'enterprise.supplier.portal_token_revoked',
                $actor,
                $supplier,
                null,
                ['supplier_id' => $supplier->id, 'portal_token_id' => $token->id],
            );
        }
    }

    public function resolvePortalToken(string $plainToken, string $capability): EnterpriseSupplierPortalToken
    {
        abort_unless(in_array($capability, self::PORTAL_CAPABILITIES, true), 404);
        $token = EnterpriseSupplierPortalToken::query()
            ->with('supplier')
            ->where('token_hash', hash('sha256', $plainToken))
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->firstOrFail();

        abort_unless(in_array($capability, (array) $token->capabilities, true), 403);
        abort_unless($token->supplier instanceof EnterpriseSupplier && $token->supplier->status === 'active', 403);

        $token->forceFill(['last_used_at' => now()])->save();

        return $token;
    }

    /** @return array<string,mixed> */
    public function portalData(string $plainToken): array
    {
        $token = $this->resolvePortalToken($plainToken, 'rfq');
        $supplier = $token->supplier;

        $rfqs = DB::table('enterprise_procurement_rfq_suppliers as invited')
            ->join('enterprise_procurement_rfqs as rfq', 'rfq.id', '=', 'invited.rfq_id')
            ->leftJoin('enterprise_procurement_quotes as quote', function ($join) use ($supplier): void {
                $join->on('quote.rfq_id', '=', 'rfq.id')
                    ->where('quote.supplier_id', '=', $supplier->id);
            })
            ->where('invited.supplier_id', $supplier->id)
            ->whereIn('rfq.status', ['published', 'evaluating'])
            ->where(function ($query): void {
                $query->whereNull('rfq.closes_at')->orWhere('rfq.closes_at', '>', now());
            })
            ->orderBy('rfq.closes_at')
            ->orderByDesc('rfq.id')
            ->get([
                'rfq.id',
                'rfq.rfq_number',
                'rfq.title',
                'rfq.currency_code',
                'rfq.closes_at',
                'invited.status as invitation_status',
                'quote.id as quote_id',
                'quote.status as quote_status',
                'quote.total_amount as quote_total',
            ]);

        $lines = DB::table('enterprise_procurement_rfq_lines')
            ->whereIn('rfq_id', $rfqs->pluck('id')->all())
            ->orderBy('rfq_id')
            ->orderBy('line_no')
            ->get()
            ->groupBy('rfq_id');

        return [
            'token' => $token,
            'supplier' => $supplier,
            'rfqs' => $rfqs,
            'lines' => $lines,
        ];
    }

    public function submitQuote(string $plainToken, int $rfqId, array $attributes): void
    {
        $token = $this->resolvePortalToken($plainToken, 'rfq');
        $supplier = $token->supplier;
        $scope = $this->scopeForStore((int) DB::table('enterprise_procurement_rfqs')->where('id', $rfqId)->value('store_id'));

        abort_unless(
            DB::table('enterprise_procurement_rfqs')
                ->where('id', $rfqId)
                ->where('organization_id', $scope->organizationId)
                ->where('company_id', $scope->companyId)
                ->where('store_id', $scope->storeId)
                ->exists(),
            404,
        );
        abort_unless(
            DB::table('enterprise_procurement_rfq_suppliers')
                ->where('rfq_id', $rfqId)
                ->where('supplier_id', $supplier->id)
                ->exists(),
            403,
        );

        $this->sourcing->recordSupplierQuote(
            $scope,
            $rfqId,
            [...$attributes, 'supplier_id' => $supplier->id],
        );
    }

    public function declineRfq(string $plainToken, int $rfqId): void
    {
        $token = $this->resolvePortalToken($plainToken, 'rfq');
        $supplier = $token->supplier;
        $updated = DB::table('enterprise_procurement_rfq_suppliers')
            ->where('rfq_id', $rfqId)
            ->where('supplier_id', $supplier->id)
            ->whereIn('status', ['invited', 'responded'])
            ->update([
                'status' => 'declined',
                'declined_at' => now(),
                'updated_at' => now(),
            ]);
        abort_if($updated < 1, 409, 'The RFQ invitation is not available for decline.');

        $this->audit->record(
            'enterprise.supplier.rfq_declined',
            null,
            $supplier,
            null,
            ['supplier_id' => $supplier->id, 'rfq_id' => $rfqId],
        );
    }

    public function registerPortalDocument(string $plainToken, array $attributes): void
    {
        $token = $this->resolvePortalToken($plainToken, 'documents');
        $supplier = $token->supplier;
        $data = Validator::make($attributes, [
            'category' => ['required', 'string', 'max:64'],
            'title' => ['required', 'string', 'max:190'],
            'reference' => ['nullable', 'string', 'max:190'],
            'expires_at' => ['nullable', 'date'],
        ])->validate();

        $document = EnterpriseSupplierDocument::query()->create([
            'supplier_id' => $supplier->id,
            ...$data,
            'created_by' => null,
        ]);

        $this->audit->record(
            'enterprise.supplier.portal_document_registered',
            null,
            $supplier,
            null,
            [
                'supplier_id' => $supplier->id,
                'document_id' => $document->id,
                'category' => $document->category,
                'title' => $document->title,
                'expires_at' => $document->getRawOriginal('expires_at'),
            ],
        );
    }

    private function scopeForStore(int $storeId): EnterpriseDataScope
    {
        abort_if($storeId < 1, 404);
        $scope = $this->hierarchy->scopeForStore($storeId);
        abort_if($scope === null, 409, 'Enterprise store scope is required.');

        return EnterpriseDataScope::fromHierarchy($scope);
    }

    private function visibleSupplier(EnterpriseDataScope $scope, int $supplierId): EnterpriseSupplier
    {
        return EnterpriseSupplier::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->whereKey($supplierId)
            ->where(function ($query) use ($scope): void {
                $query->where('scope_type', 'company')
                    ->orWhere(fn ($store) => $store
                        ->where('scope_type', 'store')
                        ->where('store_id', $scope->storeId));
            })
            ->firstOrFail();
    }

    private function authorizedScope(
        User $actor,
        ResolvedTenantContext $context,
        string $permission,
    ): EnterpriseDataScope {
        abort_unless($this->modules->isEnabled('procurement'), 404, 'Procurement module is disabled.');
        abort_if($context->enterpriseScope === null, 409, 'Enterprise data scope is required.');

        $scope = $context->enterpriseScope;
        $this->authorizer->authorize($actor, $context, $permission, 'company', $scope->companyId);

        return $scope;
    }
}
