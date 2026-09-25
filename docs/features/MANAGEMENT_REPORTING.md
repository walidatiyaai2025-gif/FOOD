# FOODEX Management Reporting Suite

Issue #99 provides one permission-aware Reports Center for Orders & Sales, Products & Catalog, Customers/B2B and Store/Delivery Operations.

## KPI rules

- Reporting timestamps and display periods use Asia/Kuwait.
- Gross order value is the sum of order grand_total after server-side filters.
- Recognized revenue excludes orders whose status is cancelled or refunded.
- Average order value uses the same recognized-revenue population.
- Product quantity/revenue excludes cancelled/refunded orders and uses order-item snapshots to avoid current-price drift.
- Best/least product rankings are available independently by quantity and revenue; zero-sales products are listed separately.
- New customers are customers whose first-ever order falls inside the selected reporting period. Other ordering customers are returning.
- Delivery duration is completed_at minus assigned_at for assignments that have both timestamps.
- Inventory metrics use the authoritative current inventories table and are not historical period metrics.

## Filtering and authorization

All report filtering is server-side. Supported filters are date range, store, channel, order status, product, category, customer/account and payment provider. Store-scoped users must select an assigned store that grants reports.view. Exports independently require reports.export. B2B_ADMIN reporting is constrained to the B2B channel unless the user also holds a broader global operations/finance role.

## Exports

XLSX, DOCX and PDF are generated server-side from the complete filtered report up to the governed limit of 5,000 rows.

- XLSX uses typed numeric cells, inline text cells, a frozen header row and auto-filter. Text beginning with =, +, - or @ is neutralized to prevent formula injection.
- DOCX uses bilingual WordprocessingML, RTL paragraph/table direction for Arabic, management header information and footer page numbering.
- PDF is a real PDF document with bilingual metadata and RTL-oriented line output.
- All formats contain report title, generation timestamp, period, KPI summary and detail table.
- File names include report type, reporting period and generation timestamp.
- Every export records report.exported in the audit log with format, filters, row count, truncation state and limit. No credentials or secrets are included.

The interactive Admin screen is capped at 100 detail rows. Export is capped at 5,000 detail rows and returns X-FOODEX-Export-Limit so large requests cannot freeze the dashboard. The report metadata declares whether a result was truncated.
