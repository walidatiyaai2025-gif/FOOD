# FOODEX 1.0.77 — Certified Production Release Notes

**Status: CERTIFIED FOR FINAL RELEASE COMPOSITION.** The reversible 1.0.77 production-launch program (#1380) completed independent certification in PR #1411. Final exact-head certification passed all required workflows, including FOODEX 1.0.77 Final Certification and Required CI Gate.

## Release identity and Customer freeze

- Dashboard / repository release: `1.0.77`.
- Customer app: intentionally carried forward unchanged as `1.0.76+76` from frozen baseline `4de5a24b2ca6bd9b448a79ca05f7f44c7d428f4d` (tree `7e5828423d2b747afcd607a643c853495024328c`).
- Driver app: `1.0.77+77`.
- Van app: `1.0.77+77`.
- The Customer tree remains byte-for-byte unchanged under `apps/customer_app/**`.
- Final certification merge commit on the integration branch: `6941e62b41926acd9049e6d0d3411d13e4789286`.

## Certified production scope

- Real 25-product FOODEX launch catalog with field-level official/inferred/derived provenance (#1383).
- Production-quality launch imagery with checksum/provenance manifest (#1382).
- Verified pre-launch snapshot and exact restore engine (#1384).
- Audited Dashboard Production / Legacy reversible dataset switch (#1385).
- Production Launch Gate and source-bound launch report (#1386).
- 1.0.77 release-line contract with frozen Customer lineage (#1381, #1387).
- Independent reversible launch certification, exact restore/re-launch proof, and Customer zero-diff (#1388).

## Final publication contract

The temporary 1.0.77 production HOLD has been retired only after independent certification passed. Final release assets must now be produced by the canonical Release Factory from the exact promoted `main` source SHA.

Release completion still requires the canonical factory to produce and validate:

- Dashboard Update Center bundle;
- Customer carried-forward APK from the frozen Customer source identity;
- Driver and Van 1.0.77+77 APKs;
- Laravel Setup ZIP and clean-install evidence where required;
- `Release/BUILD_INFO.json` and `Release/LATEST_RELEASE.json` bound to the exact final implementation source SHA.

No generated release artifact may be hand-edited or relabeled to bypass the release contract.
