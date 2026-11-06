# PoketFlow Main Marketplace — Deployment Runbook

## Current release state

This rebuild is developed under `marketplace/` on the draft branch `rebuild/locked-marketplace-v1`.
It is not production-ready, has not been deployed, and has not run a migration against the live database.

The main domain document root is expected to be `/home/poketflo/public_html`. The separate blog directory `/home/poketflo/public_html/pf-blog` must remain untouched.

## Deployment layout

When all release gates pass, publish the **contents** of `marketplace/` into the main domain document root. Do not publish the repository root. Do not copy the legacy root-level CPA application files into production. Keep `tools/`, `database/migrations/`, `docs/`, and the Git metadata outside the document root.

Expected public entry points after deployment:

- `/index.php` — marketplace landing page
- `/login.php` — existing-member login
- `/dashboard.php` — unified member dashboard
- `/tasks.php` — worker task discovery
- `/campaigns.php` — advertiser task campaigns
- `/wallet.php` — separate worker and advertiser ledgers
- `/admin/finance.php` — restricted finance administration
- `/api/blog-events.php` — signed blog event receiver

The private configuration remains at `/home/poketflo/private/poketflow-main-database.php`, outside `public_html`. The CLI installer is run from the repository, not through a browser.

## Mandatory pre-deployment gates

1. Obtain fresh private backups of both `poketflo_db` and `poketflo_blog`. Verify the backup files are non-empty and record their checksums.
2. Run `tools/audit-legacy-database.php` in CLI read-only mode and retain its private report. It must establish exact account IDs, statuses, password hash formats, wallet transaction totals by account/currency/status, and legacy table structures.
3. Reconcile legacy worker balances, advertiser balances, withdrawals, and transaction histories. Do not assume the new `pf_wallets` start at zero is safe for existing members.
4. Restore both backups into disposable databases. Apply the additive main migrations in order (001, 002, 003, then 004) to the disposable main database only. Apply the blog integration migration to the disposable blog database only. Run `php tools/import-legacy-financial-history.php` as a read-only dry run, then run it with `--apply` only on the disposable clone after checking its report.
5. Run login tests against existing accounts using their original passwords. Do not change password hashes or force resets.
6. Test funding approval, campaign reservation, worker submission, approval/reward credit, duplicate review, campaign rejection/refund, withdrawal hold, withdrawal paid, withdrawal rejection/release, and ledger reconciliation.
7. Test the signed blog sync and event endpoints, including invalid signatures, expired timestamps, replayed nonces, duplicate event keys and inactive campaigns.
8. Verify transactional email delivery for registration, verification resend and password recovery. Complete admin access hardening, blog sponsorship management, payment-provider integration, disputes/fraud controls, and mobile/desktop smoke tests before calling this a complete release.

## Safe installation sequence (only after approval)

1. Create the private database configuration with `php tools/create-db-config.php` from the repository root. Enter the database password only at its hidden terminal prompt. Do not paste passwords into chat, shell commands, or GitHub.
2. Confirm the installer connects to the intended database. This step writes only a private config file; it does not migrate or modify application tables.
3. Apply migrations to a disposable database and verify them before any production migration.
4. Obtain explicit approval before running any migration on the live database.
5. Publish only the contents of `marketplace/` to the main document root, preserving `pf-blog/` and its separate database.
6. Verify HTTPS, secure session cookies, permissions, error logs, login, dashboard, and admin access.

## Rollback

Keep the previous document-root backup and both database backups. If application smoke tests fail, restore the previous document root. Restore database backups only if a production migration was explicitly approved and has changed data; never use rollback as a reason to overwrite new member activity without first reconciling it.

## Important boundaries

- Never expose the private config, SQL migration files, audit reports, or CLI tools under the public document root.
- Never give the blog database direct access to the main financial database.
- Never enable OGAds, CPAGrip, Indoleads, or other external CPA offer-wall integrations in this marketplace rebuild.
- Never merge or deploy this draft solely because PHP syntax checks pass.
