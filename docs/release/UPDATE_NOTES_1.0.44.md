# FOODEX 1.0.44 Distribution Notes

FOODEX 1.0.44 publishes the completed Customer Journey V2 (#675) and Driver Journey V2 (#686) convergence as the synchronized Dashboard, Customer and Driver distribution.

Included:
- Customer NEW-only Retail runtime covering store-scoped catalog, product, cart, authentication/registration return, guest-cart merge, checkout, account/profile/addresses/favorites/notifications, authoritative orders/tracking and Dashboard operational notifications;
- Customer integrated Guest full-journey acceptance plus final legacy B2C runtime purge and anti-regression guard;
- Driver NEW-only assignment runtime covering active delivery actions, notes, failed-delivery handling, delivered proof, Dashboard delivery evidence and lifecycle notifications;
- Driver assignment-to-proof E2E acceptance plus final legacy Driver runtime purge and anti-regression guard;
- converged APP-PREVIEW and Platform Customer Commerce release gates for the NEW Customer and Driver runtimes;
- synchronized release identity: Dashboard `1.0.44`, Customer `1.0.44+44`, Driver `1.0.44+44`.

Dashboard Update Center metadata:
- target version: `1.0.44`;
- minimum current version: `1.0.6`;
- contains migrations: `true`;
- requires full redeploy: `false`;
- SHA-256: `586210c0d29c28eb9313449e340cf244fb05abc81b9ab42c7dd62d570da9c10d`;
- packaged file count: `679`;
- package size: `73,056,557` bytes.

Operational policy is unchanged: this release does not independently enable force-update, change the production minimum-supported app version, activate Driver fresh-location enforcement, or enable the Assistant by default.
