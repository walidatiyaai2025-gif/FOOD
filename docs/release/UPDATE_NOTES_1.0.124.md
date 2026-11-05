# FOODEX 1.0.124 Update Notes

- **Current → target:** Dashboard 1.0.123 → Dashboard 1.0.124 using the Admin Dashboard System Updates ZIP uploader. Earlier installations must follow the minimum-current-version constraints in their manifests.
- **Wholesale Flash:** The B2B Flash Offer editor now exposes **Customer App — Wholesale Offers page** independently of targeting individual customers. When audience fields are empty, enabled active offers appear to all eligible wholesale Customer App users (including untargeted guest discovery); owner can disable visibility per offer without disabling Van.
- **Existing offers:** A safe additive migration adds Customer App discovery to existing Van-only wholesale Flash offers, retaining their audience targeting, products, schedule and remaining inventory.
- **Security:** B2B Flash fulfillment and ordering remain VAN ONLY; Customer App visibility cannot initiate direct B2B Customer Flash reservations or B2C retail push. Preserve B2B/B2C tenant boundaries, stock filters, customer eligibility, credentials, media and live data.
- **Release:** Synchronized Customer, Driver and Van 1.0.124+124 identities (mobile binaries separate from Dashboard ZIP); full Laravel Setup ZIP and Dashboard Update ZIP are built from one protected-main SHA.
- **Required acceptance:** Success on exact-head CI, signed package origin and SHA-256/manifest checks, ZIP integrity, fresh install, upgrade with retained data, backup/recovery simulation, immutable GitHub Release assets and protected-main release-registry sync.
