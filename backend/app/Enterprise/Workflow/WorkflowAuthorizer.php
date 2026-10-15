<?php

namespace App\Enterprise\Workflow;

use App\Models\EnterpriseWorkflowRequest;
use App\Models\EnterpriseWorkflowStep;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class WorkflowAuthorizer
{
    public function canDecide(
        User $actor,
        EnterpriseWorkflowRequest $request,
        EnterpriseWorkflowStep $step,
    ): bool {
        if ((bool) $actor->is_active === false) {
            return false;
        }

        if ($step->allow_requester_decision === false
            && (int) $request->requester_id === (int) $actor->getKey()) {
            return false;
        }

        $permission = (string) $step->required_permission;
        $storeId = $request->scope_type === 'store' && $request->scope_id !== null
            ? (int) $request->scope_id
            : null;

        $direct = $actor->hasPermission($permission, $storeId)
            && $this->directScopeAllows($actor, $request);
        $delegated = $this->activeDelegationAllows($actor, $request, $permission);

        if (! $direct && ! $delegated) {
            return false;
        }

        return $this->approvalLimitAllows($actor, $request);
    }

    private function directScopeAllows(User $actor, EnterpriseWorkflowRequest $request): bool
    {
        if ($actor->hasRole('SUPER_ADMIN')) {
            return true;
        }

        if (! in_array($request->scope_type, ['organization', 'company', 'legal_entity', 'business_unit'], true)
            || $request->scope_id === null
            || ! Schema::hasTable('enterprise_user_data_scopes')) {
            return true;
        }

        $configured = $this->activeDataScopes((int) $actor->getKey());
        if (! $configured->exists()) {
            // Wave-0 workflows predate Enterprise data-scope assignments. Preserve
            // their direct RBAC behavior until an administrator configures scopes.
            return true;
        }

        return $this->activeDataScopes((int) $actor->getKey())
            ->where('scope_type', $request->scope_type)
            ->where('scope_id', (int) $request->scope_id)
            ->exists();
    }

    private function activeDelegationAllows(
        User $actor,
        EnterpriseWorkflowRequest $request,
        string $permission,
    ): bool {
        if (! Schema::hasTable('enterprise_user_delegations')) {
            return false;
        }

        $scopeKey = $request->scope_type === 'global' || $request->scope_id === null
            ? 'global'
            : $request->scope_type.':'.(int) $request->scope_id;

        $delegations = DB::table('enterprise_user_delegations')
            ->where('delegate_id', $actor->getKey())
            ->where('permission_code', $permission)
            ->whereNull('revoked_at')
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            ->whereIn('scope_key', array_values(array_unique(['global', $scopeKey])))
            ->get(['delegator_id', 'scope_type', 'scope_id']);

        foreach ($delegations as $delegation) {
            $delegator = User::query()->where('is_active', true)->find((int) $delegation->delegator_id);
            if (! $delegator instanceof User) {
                continue;
            }

            $storeId = $request->scope_type === 'store' && $request->scope_id !== null
                ? (int) $request->scope_id
                : null;

            if (! $delegator->hasPermission($permission, $storeId)) {
                continue;
            }

            if ($delegation->scope_type === 'global'
                || ($delegation->scope_type === $request->scope_type
                    && (int) $delegation->scope_id === (int) $request->scope_id)) {
                return true;
            }
        }

        return false;
    }

    private function approvalLimitAllows(User $actor, EnterpriseWorkflowRequest $request): bool
    {
        if (! Schema::hasTable('enterprise_user_approval_limits')) {
            return true;
        }

        $context = is_array($request->context) ? $request->context : [];
        $amount = $this->scaledAmount($context['amount'] ?? null);
        $currency = strtoupper(trim((string) ($context['currency'] ?? '')));

        if ($amount === null || ! preg_match('/^[A-Z]{3}$/', $currency)) {
            return true;
        }

        $limits = DB::table('enterprise_user_approval_limits')
            ->where('user_id', $actor->getKey())
            ->whereIn('workflow_key', ['*', $request->workflow_key])
            ->where('currency', $currency)
            ->where(function ($query): void {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($query): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>', now());
            })
            ->get(['amount', 'scope_key']);

        if ($limits->isEmpty()) {
            // Approval limits are opt-in policy. Existing workflows remain compatible
            // until a bounded rule is configured for this actor/workflow/currency.
            return true;
        }

        $scopeKey = $request->scope_type === 'global' || $request->scope_id === null
            ? 'global'
            : $request->scope_type.':'.(int) $request->scope_id;

        $matching = $limits->where('scope_key', $scopeKey);
        if ($matching->isEmpty()) {
            $matching = $limits->where('scope_key', 'global');
        }

        if ($matching->isEmpty()) {
            return false;
        }

        $maximum = $matching
            ->map(fn ($limit): ?int => $this->scaledAmount($limit->amount))
            ->filter(fn (?int $value): bool => $value !== null)
            ->max();

        return is_int($maximum) && $amount <= $maximum;
    }

    private function activeDataScopes(int $userId)
    {
        return DB::table('enterprise_user_data_scopes')
            ->where('user_id', $userId)
            ->where(function ($query): void {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($query): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>', now());
            });
    }

    private function scaledAmount(mixed $amount): ?int
    {
        if (! is_scalar($amount)) {
            return null;
        }

        $amount = trim((string) $amount);
        if (! preg_match('/^(\d{1,14})(?:\.(\d{1,4}))?$/', $amount, $matches)) {
            return null;
        }

        return (int) ($matches[1].str_pad($matches[2] ?? '', 4, '0'));
    }
}
