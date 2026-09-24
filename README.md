# FOODEX

FOODEX is one commerce platform in one monorepo:

- Laravel 12 backend/API plus one role-based Management Web Dashboard.
- One Flutter Customer App for iOS + Android: B2C Guest, B2C Customer, approved B2B Customer.
- One Flutter Driver App for iOS + Android: B2C_DRIVER and B2B_DRIVER with strict separation.
- PostgreSQL for production data and Redis for cache/queues.
- REST API v1 under /api/v1 with OpenAPI as the contract source.

The repository is in Bootstrap/Foundation phase. Product feature development is blocked until the bootstrap report says READY TO START IMPLEMENTATION: YES.

The repository was initially empty, so GitHub required one minimal seed commit on main before an issue branch could exist. Commit 0a918d3dda373390d1962a8c049056737ffc1108 is that one-time seed exception. All substantive work starts on Issue #1 / chore/1-bootstrap-foundation and enters main through Pull Request.
