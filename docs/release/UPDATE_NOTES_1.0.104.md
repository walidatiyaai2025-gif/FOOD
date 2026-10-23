# FOODEX 1.0.104 Update Notes

FOODEX 1.0.104 promotes the current authoritative main lineage after 1.0.102. Version 1.0.103 is intentionally unpublished.

## Included

- Cumulative Dashboard update from the latest authoritative FOODEX source lineage.
- Self-healing full-runtime updater packaging and explicit migration/update failure diagnostics.
- Retry-safe production migration lineage, including legacy MySQL driver `received_at` normalization before freshness indexing.
- Retail Home branding/banner convergence and authoritative active-store discovery with owner read-only browsing.
- Public FOODEX Wholesale web landing, Brands directory and product-detail pages with no public pricing and no web purchase flow.
- Catalog single-source inline product operations for product data, stock quantity, low-stock threshold, status and app visibility.
- Customer, Driver and Van release identities synchronized to `1.0.104+104`.
- Fresh Customer, Driver and Van Android builds plus Laravel Setup, Dashboard update, manifests, checksums and provenance from the canonical Release Factory.

## Deployment

- Production API remains `https://foodex.50sols.com`.
- Use only the generated FOODEX 1.0.104 Dashboard update package and mobile artifacts from the canonical release workflow.
- Do not reuse the earlier FOODEX 1.0.102 package or APKs for this promotion.
