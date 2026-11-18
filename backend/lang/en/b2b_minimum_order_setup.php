<?php

return [
    'readiness' => 'Configuration readiness',
    'readiness_review' => 'Policy review required: the currently saved minimum is enforceable, but a Finance/Commercial-approved value is not certified by this precheck.',
    'help' => 'Purpose, authoritative source, example and validation',
    'source' => 'Source: obtain the approved minimum merchandise order amount for Wholesale customers from Finance/Commercial policy. This value applies after product discounts, before tax or delivery.',
    'example' => 'Illustration only: EGP 1,500.00 is a sample minimum, not an approved customer contract.',
    'default' => 'Default: EGP 1,500.00 from checkout configuration unless the Wholesale store has saved an override. Zero explicitly disables the minimum. Confirm the live default with Finance.',
    'validation' => 'Validation: numeric EGP amount between 0 and 99,999,999.99, with at most two decimal places.',
    'permission' => 'Authorization: active Wholesale operator with settings.manage for the canonical B2B store; Retail users cannot change or test this setting.',
    'test_help' => 'Test validates the candidate on the server without saving, changing checkout, sending events or recording an update audit. Save applies the approved value immediately.',
    'test' => 'Test without saving',
    'test_valid' => 'Precheck passed: EGP :amount is structurally valid. Nothing was saved; business approval and actual checkout readiness are not certified.',
];
