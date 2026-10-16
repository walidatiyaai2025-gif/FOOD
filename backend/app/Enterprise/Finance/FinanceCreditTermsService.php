<?php

namespace App\Enterprise\Finance;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementResolver;
use App\Models\EnterpriseFinanceCreditTerm;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\EnterpriseDataScope;
use App\Support\EnterpriseScopeAuthorizer;
use App\Support\ResolvedTenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class FinanceCreditTermsService
{
    public const SUBJECT_CUSTOMER = 'customer';

    public const SUBJECT_SUPPLIER = 'supplier';

    private const SUBJECT_TYPES = [
        self::SUBJECT_CUSTOMER,
        self::SUBJECT_SUPPLIER,
    ];

    private const STATUSES = ['active', 'inactive'];

    private const READ_PERMISSION = 'finance.view';

    private const MANAGE_PERMISSION = 'finance.manage';

    private const READ_CAPABILITY = 'finance.credit_terms.read';

    private const MANAGE_CAPABILITY = 'finance.credit_terms.manage';

    public function __construct(
        private readonly EnterpriseScopeAuthorizer $authorizer,
        private readonly ModuleEntitlementResolver $entitlements,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{subject_type?:string,subject_reference?:string,status?:string}  $filters
     * @return LengthAwarePaginator<int, EnterpriseFinanceCreditTerm>
     */
    public function paginate(
        User $actor,
        ResolvedTenantContext $context,
        array $filters = [],
        int $perPage = 25,
    ): LengthAwarePaginator {
        $scope = $this->authorizedScope(
            $actor,
            $context,
            self::READ_PERMISSION,
            self::READ_CAPABILITY,
        );
        $validated = Validator::make($filters, [
            'subject_type' => ['nullable', Rule::in(self::SUBJECT_TYPES)],
            'subject_reference' => ['nullable', 'string', 'max:128'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
        ])->validate();
        $perPage = max(10, min(100, $perPage));

        return $this->policyQuery($scope)
            ->when(
                $validated['subject_type'] ?? null,
                fn (Builder $query, string $type): Builder => $query->where('subject_type', $type),
            )
            ->when(
                $validated['subject_reference'] ?? null,
                fn (Builder $query, string $reference): Builder => $query
                    ->where('subject_reference', trim($reference)),
            )
            ->when(
                $validated['status'] ?? null,
                fn (Builder $query, string $status): Builder => $query->where('status', $status),
            )
            ->orderBy('subject_type')
            ->orderBy('subject_reference')
            ->orderByDesc('valid_from')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /** @param array<string, mixed> $attributes */
    public function create(
        User $actor,
        ResolvedTenantContext $context,
        array $attributes,
    ): EnterpriseFinanceCreditTerm {
        $scope = $this->authorizedScope(
            $actor,
            $context,
            self::MANAGE_PERMISSION,
            self::MANAGE_CAPABILITY,
        );
        $data = Validator::make($attributes, [
            'subject_type' => ['required', Rule::in(self::SUBJECT_TYPES)],
            'subject_reference' => ['required', 'string', 'max:128'],
            'credit_limit' => ['nullable', 'regex:/^\d{1,15}(?:\.\d{1,3})?$/'],
            'payment_terms_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'valid_from' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
        ])->validate();

        $type = (string) $data['subject_type'];
        $reference = trim((string) $data['subject_reference']);
        if ($reference === '') {
            throw ValidationException::withMessages([
                'subject_reference' => ['A subject reference is required.'],
            ]);
        }
        if ($type === self::SUBJECT_CUSTOMER && ! isset($data['credit_limit'])) {
            throw ValidationException::withMessages([
                'credit_limit' => ['Customer credit policy requires a credit limit.'],
            ]);
        }

        $validFrom = CarbonImmutable::parse((string) $data['valid_from'])->toDateString();
        $validUntil = isset($data['valid_until'])
            ? CarbonImmutable::parse((string) $data['valid_until'])->toDateString()
            : null;
        $status = (string) ($data['status'] ?? 'active');

        if (
            $status === 'active'
            && $this->policyQuery($scope)
                ->where('subject_type', $type)
                ->where('subject_reference', $reference)
                ->where('status', 'active')
                ->whereDate('valid_from', '<=', $validUntil ?? '9999-12-31')
                ->where(function (Builder $query) use ($validFrom): void {
                    $query->whereNull('valid_until')
                        ->orWhereDate('valid_until', '>=', $validFrom);
                })
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'valid_from' => ['Effective policy windows cannot overlap for the same Finance subject.'],
            ]);
        }

        $policy = EnterpriseFinanceCreditTerm::query()->create([
            'organization_id' => $scope->organizationId,
            'company_id' => $scope->companyId,
            'legal_entity_id' => $scope->legalEntityId,
            'subject_type' => $type,
            'subject_reference' => $reference,
            'credit_limit' => isset($data['credit_limit'])
                ? $this->formatMills($this->toMills($data['credit_limit']))
                : null,
            'currency_code' => strtoupper((string) $data['currency_code']),
            'payment_terms_days' => (int) $data['payment_terms_days'],
            'valid_from' => $validFrom,
            'valid_until' => $validUntil,
            'status' => $status,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        $this->audit->record(
            'enterprise.finance.credit_terms_created',
            $actor,
            $policy,
            null,
            $policy->fresh()->toArray(),
        );

        return $policy->refresh();
    }

    public function setStatus(
        User $actor,
        ResolvedTenantContext $context,
        int $policyId,
        string $status,
    ): EnterpriseFinanceCreditTerm {
        $scope = $this->authorizedScope(
            $actor,
            $context,
            self::MANAGE_PERMISSION,
            self::MANAGE_CAPABILITY,
        );
        Validator::make(['status' => $status], [
            'status' => ['required', Rule::in(self::STATUSES)],
        ])->validate();

        $policy = $this->policyQuery($scope)->whereKey($policyId)->firstOrFail();
        if ($policy->status === $status) {
            return $policy;
        }

        if ($status === 'active') {
            $end = $policy->valid_until === null
                ? '9999-12-31'
                : CarbonImmutable::parse((string) $policy->valid_until)->toDateString();
            $overlap = $this->policyQuery($scope)
                ->whereKeyNot($policy->id)
                ->where('subject_type', $policy->subject_type)
                ->where('subject_reference', $policy->subject_reference)
                ->where('status', 'active')
                ->whereDate('valid_from', '<=', $end)
                ->where(function (Builder $query) use ($policy): void {
                    $query->whereNull('valid_until')
                        ->orWhereDate(
                            'valid_until',
                            '>=',
                            CarbonImmutable::parse((string) $policy->valid_from)->toDateString(),
                        );
                })
                ->exists();
            if ($overlap) {
                throw ValidationException::withMessages([
                    'status' => ['Policy cannot activate while an effective window overlaps.'],
                ]);
            }
        }

        $before = $policy->toArray();
        $policy->forceFill([
            'status' => $status,
            'updated_by' => $actor->id,
        ])->save();

        $this->audit->record(
            'enterprise.finance.credit_terms_status_changed',
            $actor,
            $policy,
            $before,
            $policy->fresh()->toArray(),
        );

        return $policy->refresh();
    }

    public function effective(
        User $actor,
        ResolvedTenantContext $context,
        string $subjectType,
        string $subjectReference,
        string $asOf,
    ): EnterpriseFinanceCreditTerm {
        $scope = $this->authorizedScope(
            $actor,
            $context,
            self::READ_PERMISSION,
            self::READ_CAPABILITY,
        );
        Validator::make([
            'subject_type' => $subjectType,
            'subject_reference' => $subjectReference,
            'as_of' => $asOf,
        ], [
            'subject_type' => ['required', Rule::in(self::SUBJECT_TYPES)],
            'subject_reference' => ['required', 'string', 'max:128'],
            'as_of' => ['required', 'date'],
        ])->validate();

        $reference = trim($subjectReference);
        $date = CarbonImmutable::parse($asOf)->toDateString();

        return $this->policyQuery($scope)
            ->where('subject_type', $subjectType)
            ->where('subject_reference', $reference)
            ->where('status', 'active')
            ->whereDate('valid_from', '<=', $date)
            ->where(function (Builder $query) use ($date): void {
                $query->whereNull('valid_until')
                    ->orWhereDate('valid_until', '>=', $date);
            })
            ->orderByDesc('valid_from')
            ->orderByDesc('id')
            ->firstOrFail();
    }

    /**
     * @return array{
     *     customer_id:int,
     *     currency:string,
     *     credit_limit:float,
     *     outstanding_receivable:float,
     *     available_credit:float,
     *     payment_terms_days:int,
     *     policy_id:int
     * }
     */
    public function customerAvailableCredit(
        User $actor,
        ResolvedTenantContext $context,
        int $customerId,
        string $asOf,
    ): array {
        if ($customerId < 1) {
            throw ValidationException::withMessages([
                'customer_id' => ['A valid customer is required.'],
            ]);
        }

        $policy = $this->effective(
            $actor,
            $context,
            self::SUBJECT_CUSTOMER,
            (string) $customerId,
            $asOf,
        );
        $scope = $context->enterpriseScope;
        abort_if($scope === null, 409, 'Enterprise data scope is required.');

        $allocations = DB::table('enterprise_ar_allocations')
            ->selectRaw('ar_document_id, SUM(amount) as allocated_amount')
            ->where('status', 'posted')
            ->groupBy('ar_document_id');

        $outstanding = (float) DB::table('enterprise_ar_documents as document')
            ->leftJoinSub(
                $allocations,
                'allocations',
                'allocations.ar_document_id',
                '=',
                'document.id',
            )
            ->where('document.organization_id', $scope->organizationId)
            ->where('document.company_id', $scope->companyId)
            ->where('document.legal_entity_id', $scope->legalEntityId)
            ->where('document.customer_id', $customerId)
            ->where('document.currency_code', strtoupper((string) $policy->currency_code))
            ->where('document.status', 'posted')
            ->whereDate('document.entry_date', '<=', CarbonImmutable::parse($asOf)->toDateString())
            ->selectRaw(
                'COALESCE(SUM(document.amount - COALESCE(allocations.allocated_amount, 0)), 0) as outstanding',
            )
            ->value('outstanding');

        $limit = round((float) $policy->credit_limit, 3);
        $outstanding = round(max($outstanding, 0), 3);

        return [
            'customer_id' => $customerId,
            'currency' => strtoupper((string) $policy->currency_code),
            'credit_limit' => $limit,
            'outstanding_receivable' => $outstanding,
            'available_credit' => round(max($limit - $outstanding, 0), 3),
            'payment_terms_days' => (int) $policy->payment_terms_days,
            'policy_id' => (int) $policy->id,
        ];
    }

    public function supplierDueDate(
        User $actor,
        ResolvedTenantContext $context,
        string $supplierReference,
        string $entryDate,
    ): string {
        $date = CarbonImmutable::parse($entryDate);
        $policy = $this->effective(
            $actor,
            $context,
            self::SUBJECT_SUPPLIER,
            $supplierReference,
            $date->toDateString(),
        );

        return $date->addDays((int) $policy->payment_terms_days)->toDateString();
    }

    private function authorizedScope(
        User $actor,
        ResolvedTenantContext $context,
        string $permission,
        string $capability,
    ): EnterpriseDataScope {
        abort_if($context->enterpriseScope === null, 409, 'Enterprise data scope is required.');
        $scope = $context->enterpriseScope;

        $this->authorizer->authorize(
            $actor,
            $context,
            $permission,
            'legal_entity',
            $scope->legalEntityId,
        );
        abort_unless(
            $this->entitlements->allows(
                FinanceFoundationService::MODULE,
                new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $scope->legalEntityId),
                $capability,
            ),
            403,
        );

        return $scope;
    }

    /** @return Builder<EnterpriseFinanceCreditTerm> */
    private function policyQuery(EnterpriseDataScope $scope): Builder
    {
        return EnterpriseFinanceCreditTerm::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId);
    }

    private function toMills(mixed $value): int
    {
        $raw = trim((string) $value);
        if (! preg_match('/^\d{1,15}(?:\.\d{1,3})?$/', $raw)) {
            throw ValidationException::withMessages([
                'credit_limit' => ['Credit limit must have at most three decimal places.'],
            ]);
        }

        [$whole, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
        $fraction = str_pad($fraction, 3, '0');

        return ((int) $whole * 1000) + (int) $fraction;
    }

    private function formatMills(int $mills): string
    {
        return intdiv($mills, 1000).'.'.str_pad((string) ($mills % 1000), 3, '0', STR_PAD_LEFT);
    }
}
