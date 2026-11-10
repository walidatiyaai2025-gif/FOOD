# V2 GOV P136 — Strict fulfillment actors

Source #2859, isolated integration `Foodex_V2` only.

**Canonical policy:** Wholesale/B2B → Van only; Retail/B2C → Driver only; no manual, Customer Service, or emergency Driver fallback for wholesale. If B2B Van routing fails, the order must be `awaiting_dispatch`, never assigned to a Driver.

The dispatch writer and direct Driver assignment API enforce the same rule before mutations; Dashboard only shows the channel's eligible actors, preserving AR/EN and RTL/LTR. Previous B2B Driver assignments are historical data: the audited idempotent cutover now ends manual exceptions too and routes to a valid Van or holds the order `awaiting_dispatch`. Audit flags those rows as contradictions. Existing B2C Driver authorization and Van execution remain unchanged.

**Mobile:** Customer data/response schema unchanged; no Customer Flutter change. Van uses existing B2B API/lifecycle; no Van Flutter change. Driver legacy B2B assignment presentation and execution controls require a linked V2 Driver issue and explicit Flutter regression; this PR does not falsely claim that legacy Driver runtime is fully converged. No Schema migration and no data deletion.

**Verification:** Service channel denial/allow, Dashboard and direct API bypass attempts with zero writes, cutover legacy manual revocation, migration audit, AR/EN labels, full exact-head required CI. Keep issue open until Driver mobile and runtime acceptance converge. No merge to `main` or production deployment.
