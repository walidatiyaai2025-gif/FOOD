<?php

namespace App\Enterprise\Workflow;

use App\Models\EnterpriseWorkflowRequest;
use App\Models\EnterpriseWorkflowStep;
use App\Models\User;
use App\Services\EnterpriseSecurityAccessResolver;

final class WorkflowAuthorizer
{
    public function __construct(private readonly EnterpriseSecurityAccessResolver $access) {}

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

        /** @var array<string, mixed>|null $contextValue */
        $contextValue = $request->getAttribute('context');
        $context = is_array($contextValue) ? $contextValue : [];
        $amount = isset($context['amount']) && is_numeric($context['amount'])
            ? (string) $context['amount']
            : null;
        $currency = isset($context['currency']) && is_string($context['currency'])
            ? strtoupper($context['currency'])
            : null;

        return $this->access->allows(
            $actor,
            (string) $step->required_permission,
            (string) $request->scope_type,
            $request->scope_id === null ? null : (int) $request->scope_id,
            $amount,
            $currency,
        );
    }
}
