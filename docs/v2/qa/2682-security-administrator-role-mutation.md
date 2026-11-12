# FOODEX V2 — #2682 Security Administrator role/action authority (T122R2)

Integration: **Foodex_V2 only**. Parent: #2503. Source: \`FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx\` row T122R2. No main, production, staging deployment, release, signing or tag.

## Owner and canonical source

The active seeded global \`SECURITY_ADMINISTRATOR\` is a platform **security delegate**, not a \`SUPER_ADMIN\` or \`PLATFORM_OWNER\`. It has the exact nine existing security/user/role/delegation/approval permissions in \`backend/config/job_roles.php\`; it does not obtain finance, GL posting, inventory, order-dispatch, Customer/Driver/Van execution or platform control-plane permissions. Its domain is \`administration\`, global scope and platform business context, with no ERP module readiness requirement for this control-plane role.

**Reuse**: existing \`RbacManager\`, \`SecurityController\`, explicit \`User::hasPermission\` deny precedence, audit logger, seeded \`BuiltInJobRoleCatalog\`, and the existing bilingual Security Center. Do not create a second role system, permission registry, Sidebar rule or endpoint. The already-merged **#2717 / PR #3172** owns Security Administrator navigation, AR/EN RTL/LTR, HTTP GET, inactive/roleless, no cross-channel access and the existing \`security.view\` deny control. This P2682 test is additive and targets separate **mutating** actions.

## Actual operation, protection and negative matrix

New \`backend/tests/Feature/V2SecurityAdministratorRoleMutationAuthorityTest.php\` exercises the seeded actor through real authenticated Security routes and the canonical database:

- Exact \`SECURITY_ADMINISTRATOR\` nine-grant catalog and no Finance/GL/Orders/Dispatch/stock or platform override.
- Successful reviewed assignment of a **strictly lesser** custom security-only global role; source role removed, reason and old/new effective assignment written to \`AuditLog\`. Uses the existing Security Users role editing HTTP path.
- Fail-closed attempt to grant a role with Finance management capability, \`SUPER_ADMIN\`, or internal \`PLATFORM_OWNER\` without changing target role or adding a privileged override/audit event.
- Fail-closed assignment of an active Retail store role to a security-only principal with no Retail store authority. No cross-store \`user_store_roles\` mutation.
- Explicit user-level \`DENY users.roles.manage\` overrides a seeded role grant at the **HTTP mutation** gate while preserving independent \`security.view\` read access and target data.
- AR/EN and RTL/LTR GET/navigation security contract inherited from independently executed #2717; mutated forms use existing localized validation text and existing responsive Security Center, not new UI fields.

The test must run on exact-head Required CI with real Laravel DB transactions, no production account data. Audit and permission state must remain unchanged on negative cases. Finance maker/checker, module entitlements and SoD decisions cannot be bypassed by a Security-only role; the test explicitly forbids delegation of Finance business operations rather than pretending this actor is a Finance operator.

## Independent app impact classification

| Surface | Evidence and effect |
|---|---|
| Backend/Dashboard | New focused Laravel regression + QA only; established Security Users role mutation route, audit and bilingual Security Center unchanged. |
| Customer | No Customer API/identity/credit/storefront contract or Flutter runtime change. |
| Driver | No B2C driver role, wallet, assignment, GPS, proof, status or notification change. **B2C = DRIVER ONLY** remains untouched. |
| Van | No B2B visit, trip, consignment, territory, settlement or Flutter change. **B2B = VAN ONLY** remains untouched. |

## Acceptance and release fence

Run \`php artisan test --filter=V2SecurityAdministratorRoleMutationAuthorityTest\` as part of full GitHub Laravel suite. Require Repository Policy, FOODEX V2 Isolation Gate, FOODEX Required CI Gate, PHP syntax/Pint/PHPStan/composer security, AR/EN, MySQL/Redis fresh-install/upgrade/recovery and terminal \`required-ci-gate\` all **SUCCESS on exact final PR head**.

Do not claim source acceptance or merge if the new tests uncover a missing runtime guard. Never infer a real production account state from seeded tests. Manual full visual/mobile-web UAT is deferred to V2 final under #2503; no production evidence is asserted.
