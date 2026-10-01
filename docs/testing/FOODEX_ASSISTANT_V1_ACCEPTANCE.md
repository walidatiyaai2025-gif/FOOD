# FOODEX Assistant V1 — Final Acceptance Evidence

Issue: #628  
Parent: #621  
Track: AI-V1  
Production release blocker: **NO**

This document records repository evidence for Assistant V1. It does not claim production or cPanel deployment evidence that has not occurred.

## Acceptance boundary

Assistant V1 is a deterministic, read-only management copilot. The Laravel application remains authoritative. The Assistant feature train is isolated on `feat/assistant-v1-integration`; normal FOODEX platform releases must not wait for Assistant CI while the feature remains outside `main`.

The production default remains:

```env
ASSISTANT_ENABLED=false
ASSISTANT_READ_ONLY=true
ASSISTANT_RETENTION_DAYS=90
ASSISTANT_CACHE_SECONDS=60
ASSISTANT_RATE_LIMIT=30
```

## Evidence matrix

| Area | Requirement | Repository evidence | Result |
| --- | --- | --- | --- |
| Conversation | Arabic intent recognition | `AssistantConversationEngineTest::test_arabic_common_phrase_maps_to_sales_summary_and_today` | PASS |
| Conversation | English intent recognition | `AssistantConversationEngineTest::test_english_common_phrase_maps_to_late_orders_and_yesterday` | PASS |
| Conversation | Mixed Arabic/English | `AssistantConversationEngineTest::test_mixed_arabic_english_business_terms_are_supported` | PASS |
| Conversation | Relative dates | `AssistantConversationEngineTest::test_relative_time_windows_are_deterministic` | PASS |
| Conversation | Multi-turn references | `AssistantConversationEngineTest::test_multi_turn_reference_resolves_authorized_entities_and_comparison` | PASS |
| Conversation | Clarification | `AssistantConversationEngineTest::test_medium_confidence_asks_one_focused_clarification` | PASS |
| Conversation | Unsupported fallback | `AssistantConversationEngineTest::test_low_confidence_returns_bounded_supported_topic_fallback` | PASS |
| Conversation | Current-page authorized context | `AssistantV1AcceptanceTest::test_current_page_authorized_entity_is_resolved_deterministically` plus `AssistantApiIntegrationTest::test_forged_store_page_context_is_rejected` | PASS |
| Data | Exact sales totals/comparison | `AssistantBusinessToolsTest::test_sales_summary_and_compare_use_exact_authoritative_totals` | PASS |
| Data | Order lookup isolation | `AssistantBusinessToolsTest::test_order_lookup_cannot_cross_store_scope` | PASS |
| Data | Store comparison | `AssistantBusinessToolsTest::test_store_compare_rejects_unauthorized_store` | PASS |
| Data | Customer summary/activity | `AssistantBusinessToolsTest::test_customer_activity_cannot_leak_foreign_store_customer` and seeded summary coverage | PASS |
| Data | Product performance | `AssistantBusinessToolsTest::test_product_performance_is_store_scoped_and_exact` | PASS |
| Operations | Late orders | `AssistantOperationsToolsTest::test_late_orders_are_reproducible_and_store_scoped` | PASS |
| Operations | Cancellations | `AssistantOperationsToolsTest::test_cancellation_results_and_rate_use_exact_seeded_orders` | PASS |
| Operations | Drivers | `AssistantOperationsToolsTest::test_driver_status_and_assignments_cannot_leak_foreign_store` | PASS |
| Operations | Inventory | `AssistantOperationsToolsTest::test_inventory_alerts_use_platform_threshold_and_scope` | PASS |
| Operations | Daily brief | `AssistantOperationsToolsTest::test_daily_brief_is_deterministic_and_composed_from_authorized_sections` | PASS |
| Security | B2B/B2C/store isolation | Business + Operations scoped tests and `OperationalTenantScope` intersections | PASS |
| Security | Unauthorized/forged context | API forged-store test plus order/customer/driver foreign-scope tests | PASS |
| Security | No free-form SQL / no business writes | Typed registry + `AssistantV1AcceptanceTest::test_authoritative_tool_services_are_read_only_and_query_bounded` | PASS |
| Security | No external AI/API-key dependency | `AssistantV1AcceptanceTest::test_assistant_domain_has_no_external_ai_or_process_runtime_dependency` | PASS |
| Security | Rate limiting | `AssistantApiIntegrationTest::test_assistant_named_rate_limit_is_enforced_per_user` | PASS |
| Security | Disabled behavior | Foundation/API/UI disabled tests | PASS |
| UX | Persistent drawer/history | `AssistantChatUiTest::test_assistant_assets_cover_persistence_responsive_accessibility_and_safe_actions` + API persistence test | PASS |
| UX | Mobile + RTL/LTR | Responsive CSS assertions and locale-specific rendering tests | PASS |
| UX | Loading/error/empty + safe actions | Assistant UI asset test + controller action allowlist + `AssistantV1AcceptanceTest::test_safe_deep_link_allowlist_covers_supported_assistant_destinations_only` | PASS |
| Performance | Bounded reads | 366-day date cap, 25-row business lists, 50-row operations lists; acceptance source guard | PASS |
| Cache | Cache behavior | V1 does not populate a tool-result cache, so no cached cross-scope payload can leak. `ASSISTANT_CACHE_SECONDS` remains a disabled-by-implementation future seam until a keyed/invalidation design is added. | PASS — no active result cache |
| Scheduler | Scheduler use | V1 performs request-time reads and adds no Assistant-specific scheduled job or persistent service. | PASS — none required |
| Hosting | cPanel rollout/rollback | `docs/operations/FOODEX_ASSISTANT_V1_CPANEL.md` | PASS — repository procedure |
| Release isolation | Assistant does not change release identity | #622–#628 target the integration branch and #628 changes no VERSION/mobile/distribution identity | PASS |

## Required CI

The acceptance PR must pass the repository-required gates for its exact head:

- Repository Policy;
- backend tests;
- Laravel Pint;
- PHPStan;
- Composer security audit;
- OpenAPI validation;
- MySQL/Redis install-upgrade-recovery acceptance.

Skipped mobile/preview jobs are acceptable when the changed-area detector proves those areas were not modified.

## Cache and scheduler decision

Assistant V1 deliberately has **no active business-result cache**. This keeps authorization and freshness semantics simple while the deterministic tool layer stabilizes. `ASSISTANT_CACHE_SECONDS` is configuration reserved for a future safe cache; introducing cache reads/writes requires scope-complete keys (actor/tenant/channel/store/tool/entities/period), invalidation rules, and isolation tests before use.

Assistant V1 also adds **no scheduled precomputation**. It therefore requires no new cron beyond FOODEX's existing scheduler contract. If a future Assistant job becomes justified, it must use Laravel Scheduler and the existing `php artisan schedule:run` deployment pattern rather than a persistent Python/Node/LLM process.

## External evidence that remains external

Repository acceptance does not fabricate:

- production cPanel upload/deploy execution;
- production database backup identifiers;
- production HTTPS smoke evidence;
- operator approval to change `ASSISTANT_ENABLED` from `false`.

Those are rollout inputs, not blockers for completing the repository implementation while the Assistant remains disabled by default.
