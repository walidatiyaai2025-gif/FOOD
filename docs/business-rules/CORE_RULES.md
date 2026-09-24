# Mandatory Core Rules

1. B2C customer browsing does not require login.
2. B2C checkout requires login.
3. B2B self-registration is forbidden.
4. B2B accounts are created from the dashboard only.
5. B2C Admin and B2B Admin are separate roles.
6. B2C Drivers and B2B Drivers are separate roles.
7. The same platform supports the main B2B business and multiple B2C stores.
8. Super Admin controls the whole platform.
9. B2C Admin accesses only explicitly assigned store(s).
10. Every store-dependent query enforces store scope.
11. The API is the single source of truth.
12. Mobile apps must not embed business rules that belong to backend.
13. Critical changes are auditable.
14. Important business operations are permission checked.
15. Every feature is fully testable.
16. UI-only work is never a complete feature.
