<?php

namespace Tests\Support;

use App\Enterprise\Modules\ModuleRegistry;

/**
 * Test-only evidence for fixtures that intentionally exercise ready ERP modules.
 *
 * The values here are synthetic; they must never be copied into deployment
 * configuration or used as actual acceptance / CI attestations.
 */
final class SyntheticErpWorkflowEvidence
{
    public static function install(): void
    {
        $required = config('enterprise_module_readiness.required_workflows', []);
        if (! is_array($required)) {
            return;
        }

        $registry = app(ModuleRegistry::class);

        foreach ($required as $code => $steps) {
            if (! is_string($code) || ! is_array($steps) || $steps === []) {
                continue;
            }

            $checks = [];
            foreach ($steps as $step) {
                if (! is_string($step) || $step === '') {
                    continue;
                }

                $checks[$step] = [
                    'result' => 'passed',
                    'test' => 'SyntheticErpWorkflowEvidence::'.$code.'::'.$step,
                ];
            }

            config()->set('enterprise_module_readiness.workflow_attestations.'.$code, [
                'version' => $registry->manifest($code)->version,
                'head_sha' => str_repeat('a', 40),
                'review_issue' => 2858,
                'ci_run' => 'https://github.com/walidatiyaai2025-gif/FOOD/actions/runs/1',
                'checks' => $checks,
            ]);
        }
    }
}
