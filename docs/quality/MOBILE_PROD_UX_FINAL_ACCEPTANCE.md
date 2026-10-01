# Mobile Production UX Final Acceptance

Issue: #648  
Parent mission: #641  
Integrated base: `8c228543e9a1006c37faf5f3b519be9ca0c237bb`

This lane validates the already-merged #642-#647 implementation as one integrated product state. It does not introduce business behavior.

## CI contract

A branch named `test/<issue>-mobile-prod-ux-final-acceptance` forces the Required CI Gate to run all three product validations on the same head:

- Backend CI, including production-document-root HTTP probes for Driver live-map JS/CSS, Leaflet JS/CSS/images, brand assets, backend tests/lint/static analysis/OpenAPI, and MySQL/Redis deployment acceptance.
- Customer Flutter CI, including analyze, all non-screenshot widget/unit tests, Web validation build, Android release build/brand/network checks, and unsigned iOS validation.
- Driver Flutter CI, including analyze, all non-screenshot widget/unit tests, Android location/network/native-brand checks, and unsigned iOS validation.
- Runtime Screenshot Evidence, plus a fresh Customer and Driver screenshot capture artifact for the final-acceptance head.

## Acceptance traceability

### Production/static assets
Covered by `.github/workflows/backend-ci.yml`: local production-like HTTP requests must return 200 for Driver live-map and Leaflet assets, and the Dashboard live-map Node runtime contract is exercised by `backend/tests/Browser/driver-live-map.test.cjs`.

### Driver lifecycle
`backend/tests/Feature/DriverAssignmentLifecycleTest.php` proves:
- accepted -> out_for_delivery -> delivered;
- accepted -> failed;
- out_for_delivery -> failed;
- optional delivered image proof;
- server-derived available statuses;
- order history/proof persistence;
- Dashboard/order-owner status notifications are emitted once per successful transition and are not duplicated by repeated terminal requests.

`apps/driver_app/test/driver_journey_test.dart` and `navigation_test.dart` prove the approved Home/Deliveries structure, direct start/delivered decision sheets, failure flow, proof controls, server-derived CTA/state, channel isolation and RTL behavior.

### Customer
`customer_session_store_test.dart` proves durable authenticated-session restore and channel/store context isolation. `customer_session_http_client_test.dart` proves authoritative 401 expiry handling. The app logout flow remains covered by the full Customer test suite.

`backend/tests/Feature/StorefrontAdminBuilderTest.php` proves the scoped, published Dashboard banner/media contract. `apps/customer_app/test/platform_marketplace_home_test.dart` proves the redesigned marketplace consumes API categories, banners, product data/prices, supports search/navigation, and keeps RTL/LTR behavior without treating screenshot fixture data as production payload.

### Runtime evidence
The final-acceptance screenshot job first verifies the committed evidence pack, then captures the current Customer and Driver runtime screens in Arabic and English from the exact PR head and uploads them as the `mobile-prod-ux-final-runtime-evidence` CI artifact.

## External evidence boundary

CI can prove repository behavior, production-like HTTP serving, buildability and deterministic runtime screenshots. It must not claim real production deployment or physical-device evidence. If such evidence is explicitly required later, record it as a production/device external gate rather than fabricating it.
