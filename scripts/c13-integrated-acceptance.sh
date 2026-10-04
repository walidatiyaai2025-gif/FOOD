#!/usr/bin/env bash
set -euo pipefail

fail() {
  echo "::error::$1"
  exit 1
}

require_text() {
  local file="$1"
  local text="$2"
  grep -Fq "$text" "$file" || fail "Missing C13 evidence in $file: $text"
}

routes="apps/customer_app/lib/core/routing/customer_routes.dart"
journey="apps/customer_app/test/b2b_journey_test.dart"
reporting="backend/tests/Feature/B2bReportingTest.php"
ledger="backend/tests/Feature/B2bAccountLedgerContractTest.php"
finance="backend/tests/Feature/B2bFinanceTest.php"
pricing="backend/tests/Feature/AuthoritativePricingQuoteTest.php"
plan="docs/execution/C13_CUSTOMER_13_SCREEN_JOURNEY_PLAN.md"

test -f "$routes" || fail "Customer route registry is missing"
test -f "$journey" || fail "B2B Customer journey tests are missing"
test -f "$plan" || fail "C13 authoritative execution plan is missing"

# Exactly the 13 canonical C13 screen owners must remain registered.
for symbol in \
  entry \
  b2bDashboard \
  b2bPurchaseReports \
  b2bTopProducts \
  b2bInvoices \
  b2bAccountStatement \
  b2bOrders \
  b2bOrderDetails \
  b2bInvoiceDetails \
  b2bProducts \
  b2bProductDetails \
  b2bCart \
  b2bProfile; do
  require_text "$routes" "pattern: CustomerRoutePaths.$symbol,"
done

# Screen 12 owns checkout as a sub-surface; it is not a fourteenth canonical screen.
require_text "$routes" "pattern: CustomerRoutePaths.b2bCheckout,"
require_text "$plan" "The existing checkout/address-payment route is an owned sub-surface of Screen 12"

# Visible/reachable Customer behavior across the 13-screen journey.
require_text "$journey" "B2B unauthenticated protected route hides internal redirect details"
require_text "$journey" "B2B approved customer dashboard is RTL and exposes finance areas"
require_text "$journey" "B2B dashboard renders authoritative account metrics and refreshes in place"
require_text "$journey" "B2B top products expose current catalog state, sort and product navigation"
require_text "$journey" "B2B remote routes visibly render authoritative payload fields"
require_text "$journey" "B2B collections expose approved detail navigation"
require_text "$journey" "C13 Screen 6 reconciles statement totals, direction and references"
require_text "$journey" "B2B wholesale orders list renders seller state and opens exact detail"
require_text "$journey" "B2B order details expose complete authoritative surface in English LTR"
require_text "$journey" "C13 Screen 9 renders reconciled invoice detail and visible actions"
require_text "$journey" "B2B catalog renders authoritative commerce constraints and actionable stock states in English"
require_text "$journey" "B2B product details render authoritative account pricing and inventory"
require_text "$journey" "C13 cart shows authoritative line state totals and visible remove/clear actions"
require_text "$journey" "C13 checkout exposes financial position and blocks insufficient account credit"
require_text "$journey" "C13 checkout prevents double submit with one stable attempt key"
require_text "$journey" "B2B profile is the complete visible account and finance hub"
require_text "$journey" "B2B remote journey renders loading and empty states"
require_text "$journey" "B2B remote journey classifies network/client failure safely"

# Screen 3/4 authoritative reporting and store/customer scope.
require_text "$reporting" "test_active_b2b_customer_receives_scoped_dashboard_and_purchase_aggregates"
require_text "$reporting" "test_top_products_use_current_catalog_state_and_store_scoped_pagination"
require_text "$reporting" "test_purchase_report_reconciles_filtered_metrics_categories_comparison_and_customer_scope"
require_text "$reporting" "test_reporting_rejects_unapproved_account_and_invalid_date_range"

# One finance contract must drive Dashboard, invoice list/detail, statement and checkout semantics.
require_text "$ledger" "test_signed_balance_credit_limit_and_customer_credit_use_one_authoritative_contract"
require_text "$ledger" "test_account_summary_and_statement_apis_share_currency_balance_and_running_balance"
require_text "$ledger" "test_invoice_outstanding_reconciles_allocated_ledger_payments_and_credit_notes"
require_text "$ledger" "test_opening_notes_returns_and_adjustments_are_append_only_supported_types"
require_text "$finance" "test_active_b2b_customer_can_view_owned_invoice_and_statement_with_audit"
require_text "$finance" "test_invoice_ownership_and_account_approval_are_enforced"
require_text "$pricing" "test_wholesale_account_credit_checkout_rejects_total_above_authoritative_purchasing_power"
require_text "$pricing" "test_checkout_reprices_live_price_and_historical_snapshot_does_not_drift"
require_text "$pricing" "test_wholesale_order_keeps_tier_and_price_snapshot_after_tier_changes"

# Reuse the repository-wide tenant/channel isolation acceptance instead of inventing a C13-only bypass.
bash ./scripts/commerce-isolation-acceptance.sh

echo "C13 integrated acceptance inventory PASS: 13 canonical screens + checkout sub-surface + finance/commerce/isolation contracts."
