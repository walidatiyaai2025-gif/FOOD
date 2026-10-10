# Skill: UI Screen

## Mandatory reading
For Dashboard work:
- `docs/design-reference/DASHBOARD_UI_UX_CONTRACT.md`
- `docs/quality/LOCALIZATION_CONTRACT.md`

Inspect nearby production screens/components before designing new primitives.

## Screen contract
Define route/navigation entry, actor/permission, business task, authoritative data source, filters/search, actions, loaded/empty/error/stale states, AR/EN copy, RTL/LTR, responsive targets and required runtime evidence.

## Dashboard rules
- Sidebar = business domain.
- Tabs = related function inside domain.
- Add/Create = clear FOODEX primary action.
- Create/Edit usually Modal/Drawer/Wizard when event-based.
- Manage/View opens exact record, not a generic list.
- Grid rows show useful business data.
- Row actions use one compact FOODEX-green ellipsis menu.
- No raw database IDs/keys/JSON.
- Lookup values come from authoritative Master Data.
- Geography is map-first.
- No default Bootstrap-looking action treatment.

## Mobile rules
- Data-first compact layout.
- Preserve available viewport.
- Explicit loading/empty/error/offline states.
- Deep link/push resolves an authorized live record.
- Biometric flows never store plaintext password.

## Evidence
Static markup alone does not prove an interaction. Exercise the real route/screen with representative data and capture evidence required by the owning Issue/mission.
