# PoketFlow — Locked Marketplace Architecture

## Product boundary

PoketFlow is an independent advertiser–worker marketplace. OGAds, CPAGrip and Indoleads are discontinued and must not be restored.

- One member identity, one login, one account.
- Every active member may use Earn mode, Advertise mode, or both.
- The main platform is the system of record for members, advertiser campaigns, budgets, worker submissions, rewards, wallets, withdrawals, administration and reporting.
- The blog remains a separate custom-PHP editorial application with its own database. It receives approved sponsored creatives through a narrow authenticated API and reports impressions/clicks back. It must never access the main financial database directly.
- Sponsored content must be labeled. Gambling, adult, casino and alcohol promotions are prohibited.

## Financial invariants

1. Advertiser funding is confirmed before campaign delivery.
2. Worker rewards are reserved against funded campaign budget before tasks become available.
3. A submission is payable only after advertiser/admin approval.
4. Approval, reservation consumption and worker credit happen in one database transaction.
5. Ledger entries are immutable, signed and protected by unique idempotency keys.
6. Advertiser funds and worker earnings use distinct wallet types.
7. Blog sponsorship budgets are distinct from worker-task reward budgets.
8. Withdrawals reserve funds before processing and release or settle the hold on rejection/payment.
9. Admin financial actions are audited.

## Existing-member preservation

- Retain existing users rows, primary keys, email addresses, password hashes, profile data, statuses and historical financial records.
- No bulk password reset, account recreation, user deletion or balance rewrite.
- Account modes are additive and do not replace the legacy role field.
- Existing and newly registered members receive both Earn and Advertise capabilities by default; each protected workflow checks its corresponding `pf_account_modes` capability.
- Legacy advertiser profiles marked `SUSPENDED` or `REJECTED` retain that restriction in Advertise mode, while their separate Earn mode remains available unless the member account itself is suspended.
- Legacy advertiser `PENDING` status is retained as history; new campaigns still require the marketplace's own funding and moderation workflow before delivery.
- Existing login continues to verify password_hash with PHP password_verify().
- Before any migration: inspect the exact live schema, compare the dated SQL backup, create a fresh backup, and test on a clone.

## Main modules

Authentication and account settings; Earn mode campaign discovery, task detail, submission and evidence; Advertise mode campaign creation, funding, review and performance; wallet ledger and withdrawals; admin moderation, disputes, payouts, settings and audit; blog creative delivery and event ingestion API.

## Deployment gates

No live document-root deployment until PHP syntax checks pass; migrations pass on a disposable copy of the actual database; existing-member login succeeds without changing hashes; user IDs, emails, statuses, wallet totals and transaction counts reconcile; reserve/approval/rejection/duplicate/withdrawal/refund tests pass; blog API auth, replay protection and event idempotency pass; and mobile, desktop and security smoke tests pass.

The old application is reference material only. The locked marketplace is a new build, not a revival of the old CPA offer-wall flow.