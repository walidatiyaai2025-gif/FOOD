<?php

/*
|--------------------------------------------------------------------------
| FOODEX WhatsApp CRM GA certification
|--------------------------------------------------------------------------
|
| This manifest is source-controlled evidence owned by issue #1407. Runtime
| settings, HTTP input and lifecycle values cannot create or alter it.
|
*/

return [
    'schema_version' => 1,
    'program_issue' => 1407,
    'status' => 'PASS',
    'reference' => 'FOODEX-WHATSAPP-CRM-GA-1407-V1',
    'certified_at' => '2026-10-14',
    'integration_branch' => 'feature/whatsapp-crm-dark-launch',
    'unresolved_p0_p1' => 0,
    'checks_sha256' => '7f90373f595ce94bd024e4ea1bbaf904c196456060a90d85e2621de70b72dd30',
    'checks' => [
        'module_disabled_zero_outbound' => true,
        'core_release_without_whatsapp_credentials' => true,
        'dynamic_profile_health_validation' => true,
        'atomic_primary_number_switch' => true,
        'receive_only_drain_behavior' => true,
        'inbound_webhook_outbound_send' => true,
        'idempotent_duplicate_webhook_and_send' => true,
        'provider_outage_retry_isolation' => true,
        'agent_rbac_assignment_reassignment' => true,
        'shared_inbox_customer_360' => true,
        'global_inbound_popup' => true,
        'transactional_notifications' => true,
        'templates_campaigns' => true,
        'consent_opt_out_suppression' => true,
        'emergency_kill_switch' => true,
        'audit_completeness' => true,
        'migration_rollback_safety' => true,
        'arabic_english_operational_path' => true,
        'production_like_e2e' => true,
        'unrelated_release_independence' => true,
    ],
];
