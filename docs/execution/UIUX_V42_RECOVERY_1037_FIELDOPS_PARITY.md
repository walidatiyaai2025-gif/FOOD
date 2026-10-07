# UIUX v4.2 Recovery — Field Operations ↔ Van Parity Inventory (#1037)

Mission: **#1034 — UIUX-V42-RECOVERY**  
Owner: **#1037**  
Parity authority: `docs/design-reference/VAN_DASHBOARD_PARITY_CONTRACT.md`

| Owner-approved Van surface | Field Operations Dashboard counterpart | State |
|---|---|---|
| Home operational/assignment status | Vans, assignments, fleet location and operational status | covered |
| Routes | Visit/route lookups and routing-policy control plane | covered |
| Route Map | Fleet map + map-first territory/geography operations | covered |
| Route Detail | assignment/visit/route operational records with authoritative references | covered |
| Customers / stores in field workflow | Customer + Store lookups in Visits and Field Operations workspaces | covered |
| Visit Workspace | Visits lifecycle and linked Order/no-order state | covered |
| Customer 360 field-operation context | authoritative customer/store/order references are reused; generic Customer 360 ownership stays with #1035 | covered / shared ownership |
| Territory / address serviceability | map-first Service Territories + Address Quality Territory resolution | covered |

## Cross-app authority

Van runtime must consume authoritative route/assignment/territory/customer/store/visit/order state. No mobile-local duplicate master data is introduced to compensate for missing Dashboard administration.

Final cross-app parity review remains #1042.
