#!/usr/bin/env bash
set -euo pipefail

fail() {
  echo "::error::$*"
  exit 1
}

require_file() {
  local file="$1"
  [[ -f "$file" ]] || fail "Missing #734 acceptance surface: $file"
}

require_text() {
  local file="$1"
  local needle="$2"
  require_file "$file"
  grep -Fq -- "$needle" "$file" || fail "Missing #734 acceptance contract in $file: $needle"
}

current_version="$(tr -d '\r\n' < VERSION)"
next_release_identity="1.0.46"

[[ -n "$current_version" ]] || fail "VERSION is empty"
[[ "$current_version" != "1.0.45" ]] || fail "Release identity 1.0.45 is forbidden for the #734 commerce-isolation program; next intended release is 1.0.46"

# Lane A/B — authoritative Retail Merchant identity, platform entry and no self-store purchase.
require_text backend/tests/Feature/RetailMerchantIdentityIsolationTest.php   'test_self_store_guard_blocks_existing_customer_cart_profile_and_checkout_paths'
require_text backend/tests/Feature/RetailMerchantIdentityIsolationTest.php   'test_owned_store_block_is_multi_store_scoped_and_platform_customer_identity_is_not_duplicated'
require_text backend/tests/Feature/PlatformCustomerMarketplaceTest.php   'test_guest_platform_home_exposes_platform_retail_placements_without_leaking_store_internal_banners'
require_text backend/tests/Feature/PlatformCustomerMarketplaceTest.php   'test_retail_merchant_own_store_is_hidden_and_direct_browse_is_forbidden'

# Lane C/E — authoritative B2B/B2C ownership, operations isolation and Wholesale order timeline.
require_text backend/tests/Feature/OrderOperationsIsolationTest.php   'test_super_admin_operational_inbox_defaults_to_wholesale_and_requires_explicit_retail_channel'
require_text backend/tests/Feature/OrderOperationsIsolationTest.php   'test_retail_admin_cannot_operate_foreign_order_but_can_transition_own_order'
require_text backend/tests/Feature/OrderDomainTest.php   'test_b2b_order_detail_exposes_authoritative_customer_safe_delivery_timeline'
require_text apps/customer_app/test/b2b_journey_test.dart   'B2B wholesale orders list renders seller state and opens exact detail'
require_text apps/customer_app/test/b2b_journey_test.dart   'B2B order timeline renders only authoritative events and refreshes state'

# Lane D — mandatory, channel-safe address books.
require_text backend/tests/Feature/PlatformCustomerAddressTest.php   'test_b2b_and_b2c_address_books_and_checkout_options_do_not_cross_over'
require_text apps/customer_app/test/b2b_journey_test.dart   'B2B profile is the complete visible account and finance hub'
require_text apps/customer_app/test/retail_commerce_test.dart   'checkout offers Add Address when no valid address exists'

# Lane F/G — Driver tenant scope, complete shell/navigation and authoritative assignment lifecycle.
require_text backend/tests/Feature/DriverAssignmentLifecycleTest.php   'test_retail_driver_cannot_cross_store_even_when_channel_matches'
require_text backend/tests/Feature/DriverAssignmentLifecycleTest.php   'test_cross_channel_assignment_and_execution_are_denied'
require_text backend/tests/Feature/DriverJourneyE2EAcceptanceTest.php   'test_dashboard_assignment_to_driver_proof_is_authoritative_notified_and_idempotent'
require_text apps/driver_app/test/navigation_test.dart   'B2C driver cannot navigate into B2B routes'
require_text apps/driver_app/test/navigation_test.dart   'B2B driver cannot navigate into B2C routes'
require_text apps/driver_app/test/driver_active_journey_test.dart   'Assignment Details drives authoritative accepted pickup and delivery-start states'
require_text apps/driver_app/test/driver_active_journey_test.dart   'new journey keeps the backend channel boundary'
require_text apps/driver_app/test/driver_new_only_wiring_test.dart   'production Driver runtime is new-only and legacy cannot be rewired'

# Lane H — exact notification audience/store/channel scope and deep-link reauthorization.
require_text backend/tests/Feature/NotificationAudienceIsolationTest.php   'test_retail_operational_events_reach_exact_store_audience_not_platform_super_admin'
require_text backend/tests/Feature/NotificationAudienceIsolationTest.php   'test_wholesale_operational_events_stay_in_wholesale_audience'
require_text backend/tests/Feature/NotificationAudienceIsolationTest.php   'test_revoked_driver_notification_is_visible_but_cannot_authorize_assignment_open'

# Lane J — App Preview must auto-launch Customer Published and deterministic eligible Driver.
require_text backend/tests/Feature/AppPreviewDashboardTest.php   'test_preview_center_defaults_to_customer_published_auto_launch_contract'
require_text backend/tests/Feature/AppPreviewDashboardBridgeTest.php   'test_driver_discovery_is_deterministic_and_exact_store_scoped_for_auto_launch'

# Final integrated Customer journey must have no skipped contract gates.
require_file apps/customer_app/test/e2e/guest_customer_journey_contract_test.dart
if grep -Fq 'skip:' apps/customer_app/test/e2e/guest_customer_journey_contract_test.dart; then
  fail "Guest Customer integrated acceptance still contains skipped gates"
fi
require_text apps/customer_app/test/e2e/guest_customer_journey_contract_test.dart   'authenticated multi-store and Wholesale smoke regressions stay isolated'

echo "#734 commerce-isolation acceptance contracts: PASS"
echo "current_version=$current_version"
echo "next_release_identity=$next_release_identity"
