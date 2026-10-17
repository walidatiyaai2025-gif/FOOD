# W13 Messaging Operations Completion — Issue #1981

## Scope authority

This completion extends the existing Notifications and WhatsApp surfaces only. It does not create a parallel Marketing module, campaign ledger, consent ledger, provider ledger, inbox, or customer source of truth.

Authoritative sources remain:

- `notification_campaigns` and `notification_campaign_runs` for Dashboard notification lifecycle and run failures;
- `whats_app_campaigns` and `whats_app_campaign_recipients` for WhatsApp campaign lifecycle and recipient delivery state;
- `whats_app_marketing_preferences` and `whats_app_marketing_consents` for marketing consent provenance and opt-out state;
- `whats_app_conversations` for unresolved customer-service workload and escalation drill-down;
- `whats_app_connection_profiles` plus outbound dispatch records for provider health and delivery diagnostics;
- existing campaign reports/export endpoints for recipient-level delivery, consent exclusion, failure, response and follow-up detail.

The unified overview is a read model over those authorities. It owns no replacement business state.

## Truthfulness contract

Delivery, read and reply KPIs on the unified dashboard are derived only from durable WhatsApp recipient/provider timestamps. Generic notification campaigns are counted in lifecycle totals where authorized, but they never inflate WhatsApp sent/delivered/read/replied metrics.

Failure diagnostics come from durable recipient failure state and outbound dispatch errors. Consent blocks come from recipient suppression state and canonical marketing preferences. Missing provider or engagement evidence is shown as absent/zero rather than inferred.

## Authorization and scope

The overview reuses the same visibility contracts as its source surfaces:

- WhatsApp campaign visibility delegates to `CampaignReportService::assertVisible`;
- store/channel scope delegates to `OperationalTenantScope`;
- notification campaign visibility mirrors the existing Notifications controller rules;
- provider-health detail is exposed only to users with `whatsapp.view`;
- state-changing campaign routes retain the existing manage/send permission gates.

No cross-store campaign, consent or inbox data is materialized by the overview.

## Operational drill-downs

The overview routes operators back to the existing authorities:

- WhatsApp campaign list and recipient reports;
- notification campaigns;
- WhatsApp inbox;
- WhatsApp provider connections.

Existing per-campaign CSV/XLSX exports remain the governed export path.

## Audit

Campaign create, snapshot rebuild, schedule, pause and cancel mutations record auditable before/after snapshots through the canonical `AuditLogger`. Existing notification campaign mutations and WhatsApp connection management continue using their current audit paths.

## UX / accessibility

The overview uses the shared #1968 module-dashboard primitives, the current FOODEX admin shell and existing visualization tokens. English/LTR and Arabic/RTL copy are key-parity translations. Narrow layouts collapse dashboard charts to one column and keep trend content horizontally bounded.

## Acceptance evidence

Automated coverage verifies:

1. notification campaign counts do not fabricate WhatsApp provider engagement;
2. provider-confirmed sent/delivered/read/replied data appears in the unified overview;
3. unresolved inbox workload is derived from canonical conversations;
4. lifecycle mutations emit audit events;
5. the campaign surface renders the shared module overview inside the existing admin shell.

Required repository gates remain authoritative for final merge readiness.
