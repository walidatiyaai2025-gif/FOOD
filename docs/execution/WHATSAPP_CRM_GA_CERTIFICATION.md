# FOODEX WhatsApp CRM — Final GA Certification (#1407)

Certification reference: `FOODEX-WHATSAPP-CRM-GA-1407-V1`

Integration branch: `feature/whatsapp-crm-dark-launch`

Certification date: 2026-10-14

## Verdict

**PASS / GA_READY eligible.**

The module remains **disabled by default**. Certification does not enable production messaging. Promotion to `GA_READY` is a separate audited action backed by the repository certificate, and `ENABLED` still requires an explicit Super Admin activation after the emergency outbound stop has been cleared.

Unresolved P0/P1 risks: **0**.

## Independent certification matrix

| # | Requirement | Evidence |
|---|---|---|
| 1 | Disabled module produces zero outbound traffic and fails closed | `WhatsAppPolicyTest`, `WhatsAppGaCertificationTest` |
| 2 | FOOD boots/builds/releases without WhatsApp credentials | `WhatsAppPolicyTest`, backend CI, deployment acceptance |
| 3 | Dynamic profile creation and health validation | `WhatsAppConnectionProfileTest` |
| 4 | Atomic primary-number switch | `WhatsAppConnectionProfileTest` |
| 5 | RECEIVE_ONLY / DRAIN behavior | `WhatsAppConnectionProfileTest` |
| 6 | Inbound webhook and outbound send | `WhatsAppProviderTransportTest`, `WhatsAppTransportTest` |
| 7 | Duplicate webhook/send idempotency | `WhatsAppProviderTransportTest`, `WhatsAppTransportTest` |
| 8 | Retry/provider outage isolation | `WhatsAppProviderTransportTest`, `WhatsAppTransportTest` |
| 9 | Agent RBAC, assignment and reassignment | `WhatsAppAgentInboxTest` |
| 10 | Shared inbox + Customer 360 | `WhatsAppAgentInboxTest` |
| 11 | Global inbound light-red popup with exact conversation deep link, scope isolation and dedupe | `WhatsAppInboundAlertTest`, `WhatsAppInboundPopupUiContractTest` |
| 12 | Transactional notifications | `WhatsAppTransactionalNotificationTest` |
| 13 | Templates and campaigns | `WhatsAppCampaignTest` |
| 14 | Consent and opt-out suppression | `WhatsAppCampaignTest` |
| 15 | Emergency kill switch | `WhatsAppPolicyTest`, `WhatsAppGaCertificationTest` |
| 16 | Audit completeness for sensitive lifecycle/actions | `WhatsAppPolicyTest`, lane-specific feature tests |
| 17 | Migration/rollback safety | backend fresh-migration CI plus deployment acceptance |
| 18 | Full Arabic/English operational path | AR/EN translation resources plus localization CI |
| 19 | Production-like E2E configuration | MySQL/Redis deployment acceptance plus provider transport feature acceptance |
| 20 | Unrelated FOOD release readiness remains independent | `CheckoutDomainTest`, release CI and default-disabled certification acceptance |

The machine-readable canonical matrix is `backend/config/whatsapp_certification.php`. Its 20 ordered checks are hashed; the runtime certification authority fails closed if the manifest is incomplete, reordered, mutated, marked non-PASS, or reports any unresolved P0/P1 risk.

## GA lock

- HTTP input cannot create certification evidence.
- Ordinary lifecycle transitions still cannot set `GA_READY`.
- The runtime accepts only the source-controlled #1407 certificate.
- `foodex:certify-whatsapp-ga {user} {revision}` performs the one-shot audited readiness promotion and re-engages the emergency outbound stop.
- Production activation remains impossible until lifecycle is `GA_READY`, the certificate is valid, the actor is an active `SUPER_ADMIN` with `whatsapp.activate`, the revision is current, and the emergency stop has been explicitly cleared.
- Activation is still a distinct explicit action; certification never changes lifecycle directly to `ENABLED`.

## Certification test set

The final pull request must pass the normal required repository gate. Backend CI runs the full PHPUnit suite, including all WhatsApp lanes and `WhatsAppGaCertificationTest`, fresh migrations, lint/static analysis/security audit, and the production-services MySQL/Redis deployment suite.

No Meta token, WABA ID, phone-number ID, webhook token, or signing secret is required for boot, build, migration, certification, or unrelated FOOD release validation.
