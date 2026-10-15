<?php

namespace App\Enterprise\Workflow;

use App\Models\EnterpriseWorkflowRequest;
use App\Models\EnterpriseWorkflowStep;
use App\Models\User;

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

        $storeId = $request->scope_type === 'store' && $request->scope_id !== null
            ? (int) $request->scope_id
            : null;

        return $actor->hasPermission((string) $step->required_permission, $storeId);
    }
}
