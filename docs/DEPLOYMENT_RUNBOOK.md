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
- `/blog-campaigns.php` — advertiser sponsored blog placements
- `/register.php` — new member registration and email verification
- `/wallet.php` — separate worker and advertiser ledgers
- `/admin/finance.php` — restricted finance administration
- `/admin/disputes.php` — administrator worker-appeal review
- `/api/blog-events.php` — signed blog event receiver

The private configuration remains at `/home/poketflo/private/poketflow-main-database.php`, outside `public_html`. The CLI installer is run from the repository, not through a browser.

## Mandatory pre-deployment gates

1. Obtain fresh private backups of both `poketflo_db` and `poketflo_blog`. Verify the backup files are non-empty and record their checksums.
2. Run `tools/audit-legacy-database.php` in CLI read-only mode and retain its private report. It must establish exact account IDs, statuses, password hash formats, wallet transaction totals by account/currency/status, and legacy table structures.
3. Reconcile legacy worker balances, advertiser balances, withdrawals, and transaction histories. Do not assume the new `pf_wallets` start at zero is safe for existing members.
4. Restore both backups into disposable databases. Apply the seven locked marketplace migrations in order: `20261106_001_locked_marketplace_core.sql`, `20261106_002_financial_workflows.sql`, `20261106_003_legacy_financial_preservation.sql`, `20261106_004_authentication_workflows.sql`, `20261106_005_blog_sponsorship_workflow.sql`, and `20261106_006_submission_disputes.sql`, and `20261106_007_legacy_advertiser_preservation.sql`. Migration 007 snapshots each legacy advertiser profile and current USD balance into the member-linked advertiser wallet, while archiving advertiser transaction and old ad-campaign history without replaying those historical amounts. Legacy `advertisers.id` is mapped through `advertisers.user_id` to `users.id`; unmapped or orphaned records stop the import. Do not bulk-import every file in `database/migrations/`: the repository also contains older CPA-specific migrations that are not part of this rebuild. Apply all six marketplace migrations to the disposable main database only. These migrations create the main platform's worker-task and blog-sponsorship tables. Do not apply any of these six migrations to the separate blog database. Run `php tools/import-legacy-financial-history.php` as a read-only dry run, then run it with `--apply` only on the disposable clone after checking its report.
5. Run login tests against existing accounts using their original passwords. Do not change password hashes or force resets.
6. Test funding approval, campaign reservation, worker submission, approval/reward credit, duplicate review, campaign rejection/refund, withdrawal hold, withdrawal paid, withdrawal rejection/release, worker appeals resolved for or against the worker, and ledger reconciliation.
7. Test the signed blog sync and event endpoints, including invalid signatures, expired timestamps, replayed nonces, duplicate event keys and inactive campaigns. Test text-only sponsored placement approval, rejection/refund, sync retry, advertiser suspension/pause, resume, and fixed-duration expiry.
8. Verify transactional email delivery for registration, verification resend and password recovery. Run `scripts/configure-poketflow-integration.php` from the blog repository and enter the 64-character secret printed by the main installer at its hidden prompt. The blog event endpoint must be `https://poketflow.com/api/blog-events.php` after the marketplace contents are published to the main document root; the creative sync endpoint is `https://blog.poketflow.com/api/poketflow-sync.php`. Install the blog event-flush cron and a CLI cron for `tools/expire-blog-campaigns.php` at a suitable interval (for example every 15 minutes) so fixed-duration sponsorship reservations are settled at expiry. Complete payment-provider integration, fraud controls, and mobile/desktop smoke tests before calling this a complete release.

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
