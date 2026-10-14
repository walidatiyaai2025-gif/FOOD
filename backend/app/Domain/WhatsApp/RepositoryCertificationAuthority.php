<?php

namespace App\Domain\WhatsApp;

use Throwable;

final class RepositoryCertificationAuthority implements CertificationAuthority
{
    private const PROGRAM_ISSUE = 1407;

    private const REQUIRED_CHECKS = [
        'module_disabled_zero_outbound',
        'core_release_without_whatsapp_credentials',
        'dynamic_profile_health_validation',
        'atomic_primary_number_switch',
        'receive_only_drain_behavior',
        'inbound_webhook_outbound_send',
        'idempotent_duplicate_webhook_and_send',
        'provider_outage_retry_isolation',
        'agent_rbac_assignment_reassignment',
        'shared_inbox_customer_360',
        'global_inbound_popup',
        'transactional_notifications',
        'templates_campaigns',
        'consent_opt_out_suppression',
        'emergency_kill_switch',
        'audit_completeness',
        'migration_rollback_safety',
        'arabic_english_operational_path',
        'production_like_e2e',
        'unrelated_release_independence',
    ];

    public function validEvidenceReference(): ?string
    {
        try {
            $manifest = config('whatsapp_certification');
            if (! is_array($manifest)
                || ($manifest['schema_version'] ?? null) !== 1
                || ($manifest['program_issue'] ?? null) !== self::PROGRAM_ISSUE
                || ($manifest['status'] ?? null) !== 'PASS'
                || ($manifest['unresolved_p0_p1'] ?? null) !== 0) {
                return null;
            }

            $reference = $manifest['reference'] ?? null;
            $checks = $manifest['checks'] ?? null;
            $expectedHash = $manifest['checks_sha256'] ?? null;
            if (! is_string($reference)
                || preg_match('/^FOODEX-WHATSAPP-CRM-GA-1407-[A-Z0-9._-]+$/D', $reference) !== 1
                || ! is_array($checks)
                || ! is_string($expectedHash)
                || count($checks) !== count(self::REQUIRED_CHECKS)) {
                return null;
            }

            foreach (self::REQUIRED_CHECKS as $check) {
                if (($checks[$check] ?? false) !== true) {
                    return null;
                }
            }

            if (array_keys($checks) !== self::REQUIRED_CHECKS) {
                return null;
            }

            $encoded = json_encode($checks, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (! hash_equals($expectedHash, hash('sha256', $encoded))) {
                return null;
            }

            return $reference;
        } catch (Throwable) {
            return null;
        }
    }
}
