# V2 P365 — Resulting Sidebar tree / checkbox authority boundary

Issue #2888 · source P365 · parent #2503 · integration target `Foodex_V2` ONLY.

## Canonical implementation

The existing /admin/sidebar-access page has canonical role-level permission checkboxes, role scope/channel metadata, per-node operational module readiness and an unsaved preview. This change adds a **separate resulting Sidebar tree column**, one visual result for every existing node in the canonical role matrix. It is updated by the **same** permission-checkbox change handler, with restricted, preference-hidden and role-eligible states. It cannot write any second permission or visibility policy. Role changes continue through the existing `admin.security.roles.update` action and server-side RbacManager. Protected roles remain read-only. Role-level eligibility is **never** labeled a real account grant.

Arabic/English labels, document RTL/LTR, keyboard-openable group details, live summary and responsive single-column layout are retained. The tree shows disabled, role-excluded, scope/channel, module-readiness and permission restrictions by using the same preview state calculation, not an alternate rule engine. A disabled module fails closed; an existing cosmetic Sidebar preference never masquerades as a server-side DENY. The visible action-boundary warning specifically excludes Approve, Post and Dispatch from implied permission. No side-effect API is added.

## Verification and boundaries

`backend/tests/Feature/V2ResultingSidebarTreePreviewTest.php` verifies AR/EN HTML direction, authorized role matrix versus resulting tree item count, same checkbox update handler and action-boundary text, persistent grant non-mutation for GET, and direct unprivileged GET/PATCH forbidden. Existing tests continue to own detailed role, tenant/store, module and HTTP decisions. Automated CI must pass exact PR-head Repository Policy, V2 Isolation, Laravel/Pint/PHPStan/security, AR/EN and real MySQL/Redis install-upgrade-recovery; never waive required checks.

**Customer app:** no Flutter or public/customer API changes. **Driver app:** no Flutter, dispatch or wallet/API change. **Van app:** no Flutter, routing or B2B operations/API change. Thus no mobile contract change or extra mobile Issue is implied; existing B2B VAN-only and B2C DRIVER-only contracts remain untouched.

MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL (#2503). No main merge, release/tag or production deployment. This visual preview is not independently accepted as real user/store/tenant/company access; final decisions remain with protected server routes.
