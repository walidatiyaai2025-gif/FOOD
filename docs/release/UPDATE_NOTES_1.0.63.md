# FOODEX 1.0.63

## Customer invoices release-lineage regression hotfix

- Restore the approved Customer App invoices redesign from #1072 / PR #1075 that was omitted from the immutable 1.0.62 release lineage.
- Keep the invoices title and subtitle on one compact header row.
- Use the compact responsive search/status/date/filter controls and centered empty state.
- Preserve invoice API behavior, pagination, detail navigation, Arabic RTL and English localization.
- Keep production API/runtime origin at `https://foodex.50sols.com`.
- Publish synchronized Customer, Driver and Van identities as 1.0.63+63.
- Do not move, replace, or republish the immutable `v1.0.62` tag.
