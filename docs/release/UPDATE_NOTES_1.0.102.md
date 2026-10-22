# FOODEX 1.0.102 Dashboard Update Notes

## Release source

- Release: `1.0.102`
- Mobile identity: `1.0.102+102`
- Authoritative release source: `b254723e1d05bd5514766a6ac766a012aee08e26`
- Production API: `https://foodex.50sols.com`

## Dashboard update

FOODEX 1.0.102 promotes the latest authoritative Dashboard/backend lineage and includes the Wholesale Customer UI polish merged through #2360 / PR #2361.

The generated Dashboard package is produced by the canonical Release Factory and remains subject to its manifest, checksum, cumulative-update, migration, and recovery validation contracts.

## Customer mobile

Customer is rebuilt fully for 1.0.102 from the same synchronized release source. The release includes the latest Wholesale Customer UI behavior: Home product-card sizing control, authenticated header account controls, guest-action hiding, simplified More shortcuts including Customer Service contact, simplified Company Data, store-branding account image, and compact product-card overflow protection.

## Driver and Van

Driver and Van contain no functional or UI changes for this release. Their release identities are synchronized to `1.0.102+102` and their Android artifacts are rebuilt from the same exact release source.

## Validation

Publication requires the canonical Release Factory to validate the Dashboard update, Laravel Setup, clean-install acceptance, Customer/Driver/Van Android artifacts, release provenance, manifests, and SHA-256 checksums.
