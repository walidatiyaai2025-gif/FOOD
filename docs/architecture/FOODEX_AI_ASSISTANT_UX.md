# FOODEX Assistant UX Contract

Issue: #2225 (AI-17)
Parent: #2208
Dependencies: #2215, #2216, #2219, #2220

## Placement

The Assistant remains the existing Management Dashboard Assistant. AI-17 uses Reuse + Extend.

- Reuse the persistent Assistant launcher and drawer rendered by admin._assistant-chat.
- Add a contextual Ask about this page entry in the normal page toolbar for wholesale, retail and analytics routes when that toolbar exists.
- Reuse existing contextual triggers such as the retail control-center purchase-plan action.
- Do not add a second Assistant route family, sidebar application, session store or provider path.

The authoritative HTTP surface remains /admin/assistant/*.

## Scope and context

Browser context is a hint only. The UX may show human-readable scope such as Wholesale, Retail or Analytics and B2B/B2C, but it must not expose store/entity ids or treat client state as authority.

After the server returns the signed context token from #2216, the client reuses it only while the current page-context signature is unchanged. A page, query or supplied-context change invalidates the in-memory token on the client and sends fresh page context for server resolution. The server still re-authorizes every turn.

Context tokens are not persisted in local storage.

## Answer hierarchy

An Assistant answer is presented in this order when the data exists:

1. localized grounded narrative;
2. server-provided KPI cards;
3. completion, degraded and freshness state;
4. ranked findings and deterministic recommendations;
5. approval preview when an approval-capable tool supplies one;
6. safe same-origin FOODEX drill-through actions;
7. collapsible evidence and provenance.

The UI never renders raw JSON, internal ids, evidence ids, provider/debug payloads, route names or class names. Evidence is represented with safe source classes, channel, freshness and time window.

## Failure behavior

Loading and analysis are explicit through busy and typing states. Scope denial, consent requirements, validation problems, data unavailability and network failures use localized user-safe messages. Failed submissions are retryable without inventing a result. Degraded tool execution is visible as partial rather than silently presented as complete.

Core FOODEX remains usable when the Assistant or provider is disabled or unavailable.

## Localization, direction and accessibility

- Arabic and English catalog keys are paired in backend/lang/ar/assistant_ui.php and backend/lang/en/assistant_ui.php.
- The drawer inherits Dashboard RTL/LTR direction and uses logical CSS properties.
- Desktop is a non-modal side drawer; compact/tablet/mobile presentation is modal and full width.
- Primary interactive targets are at least 44 px.
- Mobile traps keyboard focus while open, Escape closes, focus returns to the invoking control, and page scroll is locked while the modal drawer is active.
- Reduced-motion behavior remains inherited from the existing Assistant CSS.

## Responsive placement

The existing drawer remains 410 px bounded on desktop, full width below the Dashboard compact breakpoint, and full-height on mobile. Contextual toolbar access uses the existing FOODEX page toolbar/action rail and therefore inherits the Dashboard mobile overflow contract.

## API and OpenAPI

AI-17 changes only the existing authenticated Management Dashboard presentation and sends fields already owned by the existing /admin/assistant web routes. It introduces no /api/v1 endpoint or public schema change, so docs/api/openapi.yaml is intentionally unchanged.
