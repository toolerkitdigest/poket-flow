# PoketFlow Advertiser-Only Marketplace — Deployment Runbook

## Current state

The advertiser-only rebuild is being prepared on branch `rebuild/advertiser-only-marketplace`. It is not production-ready and must not be deployed until the gates below pass. Production is `/home/poketflo/public_html`; the separate blog directory `/home/poketflo/public_html/pf-blog` must remain untouched.

## Product release contents

Publish only the contents of `marketplace/` into the main domain document root after approval. Keep `tools/`, `database/migrations/`, `docs/`, tests and Git metadata outside the document root. Keep private database configuration at `/home/poketflo/private/poketflow-main-database.php`.

## Database preparation

1. Retain the verified private main database and website backups. Restore the database backup into a disposable database and verify table counts and representative records.
2. Apply migrations 001–009 to the disposable copy in their documented order, then apply `20261115_010_advertiser_only_marketplace.sql`.
3. Migration 010 is additive: it adds advertiser profiles, main-site placements/campaign metadata/events and payment-order records; it preserves legacy user identities, roles, passwords, wallets, ledger history and worker tables. Prices and new placements start disabled until configured by an administrator.
4. Confirm every existing user ID, email, password hash, status and advertiser wallet total. Confirm legacy advertiser SUSPENDED/REJECTED restrictions remain in force.
5. Do not apply migrations to the live database without explicit approval after clone tests.

## Application tests

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
