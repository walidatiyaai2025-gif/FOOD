# FOODEX 1.0.127 Update Notes

**Supported Dashboard versions:** 1.0.125 and 1.0.126 (manifest minimum 1.0.125). Update directly to 1.0.127 using the signed GitHub Release package, without an intermediate 126 installation.

- **Customer Service:** fixes the clipped multi-agent selection in the Orders grid. A responsive popup shows all authorized active agents, preserves checked team members, supports multiple assignments, optional reason and explicit clearing, without removing existing audit or notification safeguards.
- **Interrupted migrations:** preserves the validated singleton platform-owner table and its existing owner identity when the old migration was only partially recorded; inconsistent schema fails closed.
- **Permission backfill:** restores `orders.customer_service_discount` before the role-seeder step which previously failed at `clear_and_rebuild_cache`.
- **Cumulative fixes:** retains all v1.0.126 changes, Customer Groups navigation, Customer App registration, and B2B VAN ONLY/B2C DRIVER ONLY enforcement.
- **Installation and upgrade:** actual Setup ZIP fresh-install and actual 125→127 Update ZIP MySQL regression tests, preserving `.env`, uploads, orders, assignments and audit history, are required to pass before publication.
- **Release contents:** version 1.0.127 Setup ZIP, Dashboard Update ZIP, SHA-256 manifest, signed-source attestations, release notes and synchronized Customer/Driver/Van 1.0.127+127 APKs from the same final Git SHA.
- **Deployment:** verify the downloaded ZIP digest and trusted provenance independently; take a verifiable backup before uploading to Settings → System → System Updates. Confirm the live Dashboard version and health afterward. Never claim successful production installation from CI alone.
