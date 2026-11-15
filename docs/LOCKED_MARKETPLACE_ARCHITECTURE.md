# PoketFlow — Advertiser-Only Marketplace Architecture

## Product boundary

PoketFlow is an independent advertising marketplace. Advertisers register or use their existing PoketFlow account, sign in, choose available placements and campaign durations, fund campaigns, and monitor delivery. The main website is the system of record for advertisers, campaigns, pricing, budgets, payments, moderation, impressions and clicks.

Approved advertising can appear on:
- The main PoketFlow website: banner and native placements, including the public opportunities directory.
- The separate custom-PHP editorial blog at blog.poketflow.com: clearly labelled native and in-article sponsored placements. Full sponsored-article publishing is outside this first release.

The blog remains a separate application and database. It receives approved creative through the authenticated sync API and reports impression/click events to the main platform. It must never access the main financial database directly.

Worker accounts, task campaigns, submissions, evidence review, worker rewards, withdrawals, CPA conversion tracking, postbacks, offerwalls and external CPA networks are not part of the new product. Historical tables and records are retained for audit and reconciliation; they are not deleted or exposed as active product features.

## Advertiser identity and legacy preservation

- Existing user IDs, email addresses, password hashes, account statuses and historical financial records remain unchanged. Advertiser profiles are added separately.
- Existing members continue to sign in using their current email and password; no duplicate accounts or forced password resets.
- Legacy role values are retained as historical compatibility fields. New registration uses the ADVERTISER role where supported by the existing users enum.
- Advertiser profiles are additive. Existing members receive an additive advertiser profile; legacy SUSPENDED/REJECTED advertiser restrictions are copied into the profile and remain enforced.
- New registrations receive advertiser capability only. Their advertiser profile awaits administrator approval after email verification.
- The legacy worker capability and ledger data are preserved but worker workflows are disabled in the new application.

## Campaign and financial invariants

1. A campaign cannot be published until the advertiser has sufficient cleared funds and the full campaign budget is reserved.
2. Campaign pricing is controlled by administrators per placement, currency (USD or NGN) and duration. NGN and USD prices are separate; no automatic foreign-exchange conversion is implied. New placements are disabled and unpriced until an administrator configures them.
3. Campaign start/end dates are explicit; expired campaigns are not delivered.
4. Blog creative sync occurs only after approval. Failed sync leaves the campaign paused and retryable.
5. Impressions and clicks are the only delivery metrics in this release. No traffic or impression volume is guaranteed.
6. Wallet ledger entries are immutable and idempotent. Funding, reservation, release and refund actions are transactional and audited.
7. Paystack and PayPal integrations must verify payments server-side before crediting an advertiser wallet. A browser redirect alone is never proof of payment.
8. Sponsored content is labelled. Gambling, adult content, casinos and alcohol promotions are prohibited.

## Deployment gates

No production deployment until PHP syntax checks pass, the additive migration is tested against a disposable restored copy of the exact live database, existing login and account data reconcile, advertiser funding/reservation/refund tests pass, blog sync and replay/idempotency tests pass, and desktop/mobile/security smoke tests pass. Production document root and blog application/database remain untouched until explicit deployment approval.
