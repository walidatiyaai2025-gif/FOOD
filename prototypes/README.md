# FOODEX Approved UI/UX Mockup Archive

Canonical archive branch: `archive/uiux-approved-mockups`

This branch consolidates the three standalone design/mockup branches into one durable reference branch without merging stale prototype-branch history into the production recovery lineage.

Archived mockups:
- Driver: `prototypes/driver_uiux_mockup/`
- Customer: `prototypes/customer_uiux_mockup/`
- Van: `prototypes/van_uiux_mockup/`

Archived build workflows:
- `.github/workflows/driver-uiux-mockup-apk.yml`
- `.github/workflows/customer-uiux-mockup-apk.yml`
- `.github/workflows/van-uiux-mockup-apk.yml`

The workflows were retargeted to this archive branch so each mockup can still be rebuilt independently when its own path changes.

Original branches consolidated here:
- `prototype/driver-uiux-mockup`
- `prototype/customer-uiux-mockup`
- `prototype/van-uiux-mockup`

Important:
- This branch is a design/reference archive, not a production integration branch.
- Do not merge prototype fake data or prototype-only runtime behavior into production applications.
- Real FOODEX production identity, APIs, permissions, and business semantics remain authoritative.
- The Customer and Van approved-reference contracts in `docs/design-reference/` remain the production acceptance authority.
