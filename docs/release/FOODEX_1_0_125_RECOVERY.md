# FOODEX v1.0.125 Recovery (for installations on v1.0.124)

This is a **distinct recovery distribution**, not a rewrite of the immutable published `v1.0.125` tag or its assets and not a promotion past v1.0.126.

## Recovery purpose
Earlier v125/v126 Dashboard updates could fail during `run_migrations` with MySQL error 1050: `order_customer_service_discounts` already exists while Laravel did not record the migration. The repaired 125 migration validates the existing table's columns, unique indexes and foreign keys; preserves audit rows; and leaves the table intact during migration rollback. A malformed partial table fails closed without dropping data.

## Acceptance
- Source: original `v1.0.125` implementation plus narrowly scoped migration fix; all artifacts stamped with the same final recovery commit.
- Source version: v1.0.124 **only**. Update target: v1.0.125.
- Exact Update ZIP tested on MySQL after recreating missing migration ledger with existing, populated audit table and verifying `.env` plus uploaded data unchanged.
- Exact Setup ZIP carries production Composer dependencies and has passed clean MySQL migrations plus Web Installer route smoke tests. InstallerWorkflow and UpdateManager unit/feature tests pass.
- Customer, Driver and Van APKs must be generated and checked from this same final recovery commit before the complete bundle is published.
- Sigstore/GitHub OIDC build attestations bind package hashes to the producing GitHub Actions workflow and source commit. SHA-256 manifest is also included.

## Installation and trust boundary
Download exclusively from the official FOODEX GitHub recovery release. Verify the ZIP SHA-256 and GitHub build attestation against the pinned workflow run. The **existing v1.0.124 Dashboard updater checks SHA-256 only**; it cannot automatically enforce the new GitHub provenance attestation before execution. This recovery therefore requires independent origin verification before the one-time dashboard upload.

Use the Super Admin System Updates interface with the recovery Update ZIP, its SHA-256, target `1.0.125`, and minimum installed version `1.0.124`. The updater must create and verify its backup before making changes. Do not use old `v1.0.125` or `v1.0.126` ZIPs, do not overwrite `.env`, production `storage/`, uploads, or database tables, and stop on a preflight/backup failure.

Passing GitHub CI does **not** prove that the live cPanel host has been upgraded. Verify the dashboard version, functional pages and migration history on the live host after installation.