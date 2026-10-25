# FOODEX 1.0.111 Dashboard Update

## Scope

Dashboard/backend-only release. Customer, Driver and Van app identities are unchanged.

## Arabic Agent Intelligence output

- The Assistant Intelligence background Agent now writes all user-facing natural-language fields in Arabic.
- This covers executive opinion, priorities, recommendations, per-domain opinions and findings.
- JSON keys and schema enum values stay unchanged so the existing structured Agent contract remains compatible.
- Existing historical English analysis rows are not rewritten. Run **حلل المنصة الآن** after installing 1.0.111 to generate fresh Arabic Agent output.

## Safety and compatibility

- No database migration is introduced by this release.
- No mobile source or identity change is included.
- Database facts and deterministic analysis remain authoritative; the Agent is still prohibited from inventing figures, hidden causes or completed actions.
