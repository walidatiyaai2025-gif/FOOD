# UIUX v4.2 Recovery — Driver Route & Screen Inventory (#1039)

Mission: **#1034 — UIUX-V42-RECOVERY**  
Owner: **#1039**  
Canonical route registry: `apps/driver_app/lib/navigation.dart`

This inventory covers the Driver production surfaces audited by #1039. B2C and B2B share the same FOODEX components while preserving backend-derived channel isolation.

| Surface | Route / entry | Dynamic | v4.2 audit result | Evidence |
|---|---|---:|---|---|
| Login | unauthenticated app root | no | exact visible `Driver App / تطبيق السائق`; Remember Me + biometric secure-session flow | `driver_login_persistence_test.dart`, screenshot evidence AR/EN |
| B2C Home | `/driver/b2c/home` | yes | compact/full-width shell; 15s foreground refresh; resume refresh; timer disposed outside active page | `navigation_test.dart`, screenshot evidence AR/EN |
| B2B Home | `/driver/b2b/home` | yes | same authoritative shell/refresh policy with B2B channel isolation | `navigation_test.dart`, screenshot evidence AR/EN |
| B2C Deliveries | `/driver/b2c/deliveries` | yes | one-line filters; no-wrap reference; compact status; one green ellipsis; 15s foreground refresh; stale/offline retention | `driver_active_journey_test.dart`, screenshot evidence AR/EN |
| B2B Deliveries | `/driver/b2b/deliveries` | yes | same density/refresh contract with B2B backend partition | `driver_active_journey_test.dart`, screenshot evidence AR/EN |
| Assignment Detail | exact focused assignment from Deliveries/push/notification | yes | authoritative assignment/order fields; exact-record route; no generic-list deep-link fallback | `driver_active_journey_test.dart`, `driver_push_deep_link_test.dart`, detail screenshot AR/EN |
| B2C Notifications | `/driver/b2c/notifications` | yes | 15s foreground refresh + resume; stale last-confirmed data retained; revoked payload non-authorizing | `driver_notification_page_test.dart`, screenshot evidence AR/EN |
| B2B Notifications | `/driver/b2b/notifications` | yes | same notification state policy with B2B route isolation | `driver_notification_page_test.dart` |
| B2C Wallet | `/driver/b2c/wallet` when repository exposes wallet capability | yes | 15s foreground refresh + resume; stale balances retained; manual refresh fallback | `driver_wallet_page_test.dart` |
| B2B Wallet | `/driver/b2b/wallet` when repository exposes wallet capability | yes | same wallet policy with channel/capability isolation | `driver_wallet_page_test.dart` |
| Foreground new-order alert | push alert stream | event-driven | authoritative Order/Assignment identity; direct View action; stable dedupe | `firebase_push_service_test.dart`, `driver_push_deep_link_test.dart`, screenshot evidence AR/EN |
| Background / notification open | Firebase/local notification open stream | event-driven | exact `assignment_id`; order-only payload resolves against authoritative assignment repository; revoked payload fails closed | `driver_push_deep_link_test.dart`, `driver_notification_page_test.dart` |
| Already-open assignment update | notification list / repeated event | event-driven | refresh existing assignment instead of stacking another exact-record view | `driver_notification_page_test.dart` |
| Location gate | authenticated runtime gate | yes | periodic recheck + resume recheck; operations blocked when location capability becomes invalid | `driver_location_gate_test.dart` |
| Version policy gate | authenticated runtime gate | policy-driven | forced/unsupported policies block runtime; optional/current proceed | `driver_version_rollout_test.dart`, `version_policy_test.dart` |
| Runtime inspector | global inspector control | event-driven | diagnostics remain reachable without leaking credentials/private coordinates | `driver_runtime_inspector_test.dart` |

## Dynamic-surface refresh conclusion

No canonical Driver business-data page remains manual-refresh-only:

- Home: foreground periodic refresh + resume.
- Deliveries: foreground periodic refresh + resume + stale/offline truth.
- Notifications: foreground periodic refresh + resume + stale last-confirmed list.
- Wallet: foreground periodic refresh + resume + stale last-confirmed balances.
- Location gate: independent periodic capability recheck + resume.

Manual refresh remains a fallback action where exposed; it is not the only update path.

## Notification authority conclusion

- Production backend notifications normally provide both `order_id` and `assignment_id`.
- The Driver client accepts exact assignment payloads directly.
- If only authoritative `order_id` is present, the client resolves it against the authenticated Driver assignment repository and opens the record only when exactly one matching assignment is returned.
- Revoked payloads never resolve or navigate.
- Missing/ambiguous order-only payloads fail closed and show an explicit unavailable message rather than silently opening a generic list.

## Closure boundary

This inventory is owner-lane evidence for #1039. Central matrix rows remain subject to the independent #1042 runtime review and #1043 convergence gate; closing #1039 does not bypass those later gates.
