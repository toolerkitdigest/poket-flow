# Opera GX single-offer funnel (CPAGrip)

This is an isolated PHP 8.3 + MySQL mini-app intended for the document root of
`unlock.poketflow.com`. It does not modify the existing PoketFlow application
or its database.

## Files

- `index.php` — landing page
- `start.php` — creates a random 32-character tracking ID and redirects to the offer
- `postback.php` — authenticates CPAGrip's POST callback and approves the matching offer
- `success.php` — unlocks only for the same browser session after approval
- `schema.sql` — one-table MySQL schema
- `config.example.php` — safe configuration template
- `assets/style.css` — responsive design
- `assets/opera-gx-offer.png` — upload the promotional image supplied by the user

## Confirmed CPAGrip callback format

The user supplied the text of CPAGrip's Multi Postback page. It explicitly lists
these POST variables:

- `password` — the password entered in CPAGrip postback settings
- `payout` — offer payout
- `offer_id` — completed offer ID
- `tracking_id` — SubID passed on the offer URL

CPAGrip documents the tracking parameter as `tracking_id`, appended to the offer
URL. This project therefore appends a unique value as
`&tracking_id=<32-character-random-id>`.

The callback endpoint is:
`https://unlock.poketflow.com/postback.php`

The endpoint accepts POST only, checks the shared password, and only approves
offer ID `39689`. This matters if the endpoint is configured as a Global
Postback: callbacks for other offers are acknowledged but ignored and cannot
unlock this funnel.

## Installation

1. Point `unlock.poketflow.com` to its own document root, e.g.
   `/home/poketflo/unlock`.
2. Upload the contents of this folder into that document root.
3. Create a dedicated MySQL database and user in cPanel.
4. Import `schema.sql` using phpMyAdmin.
5. Copy `config.example.php` to `config.php`; set the database credentials
   and a long random `postback_password`. Do not commit `config.php`.
6. Ensure HTTPS is active.
7. This campaign uses a direct offer URL copied from CPAGrip's Offers page,
   not a Content Locker or URL/File Locker. Configure the callback under
   CPAGrip Global Postback at `https://unlock.poketflow.com/postback.php` and
   enter the same password as `config.php`. Do not attach this callback to
   `New Content Locker` in Multi Postback. CPAGrip says Global Postback is
   called for leads regardless of custom Multi Postback settings. This
   endpoint safely ignores every offer ID except 39689.
8. Before enabling traffic, confirm that the supplied offer URL is the correct
   CPAGrip-generated link for offer 39689 and that it preserves the appended
   `tracking_id` parameter.

## Offer and eligibility

Offer: `Install and Access the Latest Opera GX!`
Supplied URL: `https://getafilenow.com/show.php?l=0&u=820144&id=39689`
Target shown to visitors: Canada, desktop; email/ZIP submit.

The offer provider controls eligibility and conversion approval. Do not claim
a conversion merely because a visitor clicks or returns to the landing page.
Test with an eligible real conversion or an official CPAGrip test facility.
