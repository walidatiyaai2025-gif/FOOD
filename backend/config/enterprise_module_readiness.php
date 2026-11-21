<?php

/*
|--------------------------------------------------------------------------
| Version-scoped ERP operational certifications (V2)
|--------------------------------------------------------------------------
|
| Lifecycle ENABLED, configured entitlements, and green infrastructure probes
| are separate from reviewed operational certification. Only add a module's
| exact manifest version after its G0-G13 acceptance evidence is approved.
| There are deliberately no implicit or environment-based approvals.
|
*/

return [
    'certified_versions' => [],

    /*
     * Ed25519 verification keys, keyed by a reviewed signing-key ID.
     * Fail closed until a trusted public key is provisioned via a reviewed,
     * source-controlled configuration change. Never ship the private key.
     * Test-only synthetic keys live exclusively in memory during PHPUnit.
     */
    'trusted_public_keys' => [],

    /*
     * Full workflow evidence is required for every ERP category
     * (operations/finance/analytics). These stages are source-controlled
     * requirements, NOT a user-writable toggle or live-data attestation.
     * Adding a new ERP module without a matrix fails closed.
     */
    'required_workflows' => [
        'procurement' => [
            'request', 'review', 'approve', 'purchase_order',
            'goods_receipt', 'invoice', 'return', 'report', 'audit',
        ],
        'inventory_wms' => [
            'receive', 'reserve', 'pick', 'count',
            'adjust', 'reverse', 'report', 'audit',
        ],
        'finance' => [
            'create', 'review', 'approve', 'post',
            'reverse', 'reconcile', 'report', 'audit',
        ],
        'treasury' => [
            'receive', 'review', 'approve', 'settle',
            'reconcile', 'reverse', 'report', 'audit',
        ],
        'enterprise_bi' => [
            'read', 'filter', 'scope', 'export', 'report', 'audit',
        ],
    ],

    /*
     * NEVER put synthetic test evidence here. Each future record requires
     * exact manifest version, 40-character tested Git SHA, reviewed issue,
     * canonical Actions run URL, and a passing test identifier for EVERY
     * required workflow stage. Reviewed code + real external CI evidence
     * must be inspected before signing a record. An Ed25519 signature bound
     * to the module, version, SHA, run and exact stage tests is also required.
     * No implicit approvals or unsigned evidence.
     */
    'workflow_attestations' => [],
];
