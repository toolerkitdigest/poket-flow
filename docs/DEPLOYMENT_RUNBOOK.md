# PoketFlow Advertiser-Only Marketplace — Deployment Runbook

## Current state

The advertiser-only rebuild is being prepared on branch `rebuild/advertiser-only-marketplace`. It is not production-ready and must not be deployed until the gates below pass. Production is `/home/poketflo/public_html`; preserve the separate blog directory `/home/poketflo/public_html/pf-blog`, the existing `/home/poketflo/public_html/techdev` directory and all unrelated public_html content.

## Product release contents

After approval, deploy only the named application files from `marketplace/` into the main domain document root using a backed-up, reversible file-level release. Never delete or replace the entire `public_html` directory. Keep `tools/`, `database/migrations/`, `docs/`, tests and Git metadata outside the document root. Keep private database configuration at `/home/poketflo/private/poketflow-main-database.php`. For isolated staging, set `POKETFLOW_CONFIG` to a separate private config file that points to a disposable database; never point staging at the live database.

## Database preparation

1. Retain the verified private main database and website backups. Restore the database backup into a disposable database and verify table counts and representative records.
2. Apply migrations 001–009 to the disposable copy in their documented order, then run the guarded legacy financial-history import and reconciliation.
3. Apply `20261115_010_advertiser_only_marketplace.sql` after the legacy import so existing advertiser ACTIVE/SUSPENDED/REJECTED states are available when profiles are seeded. Migration 010 is additive: it adds advertiser profiles, main-site placements/campaign metadata/events and payment-order records; it preserves legacy user identities, roles, passwords, wallets, ledger history and worker tables. Prices and new placements start disabled until configured by an administrator.
4. Confirm every existing user ID, email, password hash, status and advertiser wallet total. Confirm legacy advertiser SUSPENDED/REJECTED restrictions remain in force.
5. Apply `20261115_011_multi_currency_placement_pricing.sql` after migration 010. It adds separate USD and NGN placement price rows; NGN prices start disabled and zero until configured.
6. Apply `20261115_012_main_site_banner_formats.sql` after migration 011 to mark the top and footer main-site slots as banner-capable.
7. Apply `20261115_013_paypal_order_reference_separation.sql` after migration 012. It preserves pending PayPal checkout order IDs separately from capture transaction IDs so duplicate webhook deliveries remain verifiable and idempotent.
7. Do not apply migrations to the live database without explicit approval after clone tests.

## Payment provider setup

After application and database tests on a disposable clone, configure credentials from the private server terminal with `php tools/configure-payment-providers.php`. Secret input is hidden and values are written only to `/home/poketflo/private/poketflow-main-database.php`. Configure the Paystack webhook to `https://poketflow.com/api/paystack-webhook.php` and the PayPal webhook to `https://poketflow.com/api/paypal-webhook.php` for `PAYMENT.CAPTURE.COMPLETED`. Never put provider secrets in GitHub, public files, or chat. Verify sandbox transactions before enabling live credentials.

## Scheduled expiry

Run `tools/expire-ad-campaigns.php` from CLI once daily using the release's private filesystem path. It expires main-site and blog placements at their configured end time, consumes the reserved fixed-duration budget, and revokes expired blog creative through the signed integration. Keep this script and all tools outside the public document root.\n\n## Application tests

- Existing users sign in with their original email and password.
- New registrations create advertiser-only profiles and advertiser wallets.
- Pending advertiser profiles cannot create or publish campaigns.
- Admin can approve/suspend advertisers and configure placement pricing.
- Advertisers can create main-site and blog campaigns, reserve funds, and see their balances and impression/click metrics.
- Campaign approval, rejection/refund, pause/resume, expiry and duplicate requests are tested transactionally.
- Paystack/PayPal payment verification is server-side; never credit funds based only on a browser callback.
- Blog sync validates signatures, timestamps, replay protection and event idempotency.
- Worker/task/withdrawal endpoints are disabled in the new application; historical data remains retained.
- No prohibited CPA provider, offerwall, postback or conversion-tracking integration exists in the deployed marketplace directory.
- Mobile, desktop, HTTPS, session cookies, CSRF, admin authorization, error logs and payment/webhook security are checked.

## Important boundaries

Never deploy the repository root or legacy root-level CPA application. Never expose private configs, SQL migrations, audit reports or CLI tools under the public document root. Never give the blog database direct access to the main financial database. Do not call the release production-ready until payment provider integration and all release gates are verified.
