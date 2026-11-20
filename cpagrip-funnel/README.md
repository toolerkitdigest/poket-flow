# Opera GX single-offer funnel (CPAGrip)

This is an isolated PHP 8.3 + MySQL mini-app intended for the document root of
`unlock.poketflow.com`. It does not use or modify the existing PoketFlow
application, its database, or its OGAds code.

## Files

- `index.php` — landing page and supplied promotional artwork
- `start.php` — creates a unique 32-character tracking ID, records the browser session, and redirects to the offer
- `postback.php` — POST-only authenticated CPAGrip callback receiver
- `success.php` — only grants access when the same browser session has a matching approved conversion
- `schema.sql` — one-table MySQL schema
- `config.example.php` — configuration template; copy to `config.php` and fill in secrets
- `assets/` — stylesheet and offer artwork

## Installation

1. Point `unlock.poketflow.com` to its own document root, for example
   `/home/poketflo/unlock`.
2. Upload the contents of this folder into that document root.
3. Create a dedicated MySQL database and database user in cPanel.
4. Import `schema.sql` using phpMyAdmin.
5. Copy `config.example.php` to `config.php`, then enter the database details
   and a long random postback password. Never commit `config.php`.
6. Ensure HTTPS is active before testing; the session cookie is Secure.
7. In the CPAGrip offer URL, the tracking value is appended as
   `tracking_id=<unique-id>`.
8. Configure CPAGrip's Global Postback to POST these fields to
   `https://unlock.poketflow.com/postback.php`:
   - `tracking_id` = the provider's tracking-id macro
   - `payout` = the provider's payout macro (optional for unlocking)
   - `transaction_id` = provider transaction macro (optional)
   - `password` = the exact secret configured in `config.php`

## Important CPAGrip verification gate

The offer URL provided for this project is:
`https://getafilenow.com/show.php?l=0&u=820144&id=39689`.

CPAGrip's public website describes postback tracking, but does not expose a
complete current public macro reference for this specific publisher account.
The `tracking_id` link parameter and POST callback field names in this
starter are based on commonly documented CPAGrip integration examples, not
verified against the user's live CPAGrip dashboard. Before enabling traffic,
confirm the exact tracking parameter, HTTP method, macro names, and password
field in CPAGrip's Postback Tools / Global Postback screen. Adjust
`postback.php` and the CPAGrip configuration to match that screen exactly.

Do not use a simulated callback to represent a real conversion. Test with a
real eligible conversion or CPAGrip's official test facility, if available.
The offer provider decides whether the conversion qualifies and approves it.

## Eligibility

The supplied offer is listed as Canada / desktop / email or ZIP submit.
This page displays those restrictions; CPAGrip and the advertiser remain
authoritative for actual geographic, device, and offer eligibility.
