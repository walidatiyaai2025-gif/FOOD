# ENG-01 Parallel Acceptance Matrix

Issue: #417 — Marketing engagement, anonymous push, live ads, order operations and platform branding.

## Anonymous customer push
- Customer devices may register before authentication.
- Anonymous registration requires a stable install_id and is Customer-app only.
- Re-registering the same token updates the same device row.
- Store-scoped registration must match the selected store channel.

## Live ads
- Retail Live Ads are hard-scoped to the selected active Retail store.
- Wholesale Live Ads remain B2B-scoped.
- Only active ads inside their schedule window are returned.
- Retail administrators cannot mutate ads owned by another store.

## Order operations
- Retail operators see only assigned-store orders.
- Foreign store filters, detail access, and order mutations return 404.
- Allowed owned-order transitions succeed and persist order status history.

## Branding and mobile
- FOODEX Economical Group remains the approved brand asset.
- Customer Flutter analyze must be clean.
- Push token lifecycle must not retain unused local device-id state.

Regression coverage:
- backend/tests/Feature/EngagementOpsIsolationTest.php
- backend/tests/Feature/OrderOperationsIsolationTest.php
