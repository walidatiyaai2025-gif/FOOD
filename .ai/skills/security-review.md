# Skill: Security Review

Use on auth, permissions, payments, finance, uploads, deep links, notifications, integrations and sensitive data flows.

## Authentication
- correct guard/token lifetime;
- logout/revocation;
- no plaintext passwords;
- biometric unlock uses secure platform storage and safe re-authentication.

## Authorization / IDOR
- scope every record lookup to actor/store/channel;
- route IDs never imply permission;
- push/deep-link payload never grants access;
- privileged UI hiding is not authorization.

## Input / output
- validate types/ranges/enums/files;
- escape/render safely;
- do not return stack traces, secrets, internal storage paths or unnecessary PII.

## Finance/payments
- server-calculated amounts;
- authenticated/signed callbacks where supported;
- idempotent webhook processing;
- immutable ledger/audit semantics;
- explicit reconciliation.

## Files/uploads
- validate type/size;
- non-executable storage;
- authorize retrieval;
- generate storage names rather than trusting user paths.

## Secrets/integrations
- no committed credentials;
- environment/config separation;
- TLS verification unless a reviewed explicit exception exists;
- correlation logging without secret leakage.

## Evidence
Add negative tests, not only happy paths. Security-sensitive fixes should prove cross-user/cross-store denial.
