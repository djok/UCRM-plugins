# Revolut Business Payments Import

Automatically records incoming Revolut Business bank payments into UCRM/UISP via
signed webhooks (Webhooks v2). Each completed incoming transfer is matched to a
client by the sender's IBAN (with an unassigned-payment fallback), mirroring the
behaviour of `paysera-payments-import` but over the real Revolut API.

## Requirements
- UCRM/UISP reachable over **HTTPS** at a public domain (the webhook target).
- A Revolut Business account with API access.

## Setup
1. **Install the plugin.** On install it generates an RSA keypair + self-signed
   certificate and writes the public certificate and instructions to the plugin
   log (Plugin detail page).
2. **Upload the certificate** in Revolut Business: *Settings (gear) → APIs →
   Business API → Add API certificate*. Paste the logged public certificate, set
   the **OAuth redirect URI** to the plugin's **Public URL** (the `public.php`
   link on the plugin detail page), and copy the issued **ClientID**.
3. **Configure the plugin:** set *Environment* (Sandbox/Production), *ClientID*,
   *OAuth redirect URI* (the same `public.php` URL), and *UCRM payment method*
   (name or ID of an existing method, e.g. `Bank transfer`). Save.
4. **Authorize:** the log prints a consent URL. Open it and authorize access —
   Revolut redirects straight back to the plugin, which captures the code,
   exchanges it for tokens, and registers the webhook automatically. (Manual
   fallback: paste the `code` into the *Authorization code* field and Save.)
5. Done — incoming payments now appear in UCRM in real time. The cron run
   (`main.php`) refreshes the token and reconciles missed events.

## How matching works
For each completed incoming transfer the plugin fetches the counterparty
(`GET /counterparty/{id}`) to obtain the sender's IBAN, then matches it against
the UCRM client's bank accounts. If the IBAN is missing or matches no client,
the payment is recorded **unassigned** (no client) so it can be reconciled
manually.

## Importing from specific accounts only
By default the plugin imports incoming transfers from **all** Revolut accounts.
To restrict it, set **Revolut accounts to import from** to one or more account
ids (comma-separated). The available accounts and their ids are listed in the
plugin log after saving the configuration, and on the plugin's Public URL with
`?accounts=1` (UCRM admin login required). Transfers landing on non-selected
accounts are ignored. The filter applies to webhooks, reconciliation, and
backfill alike.

## Monthly status page (reconciliation overview)
Open the plugin's Public URL with `?status=1` (UCRM admin login required) to
see every incoming Revolut transfer for a selected month — current by default,
any of the last 12 via the dropdown (`&month=YYYY-MM`) — with its status in
UCRM/UISP:
- ✅ recorded and attached to a client (linked),
- ⚠️ recorded but unassigned (waiting for manual attachment),
- ⏭ skipped by the manual-payment duplicate guard,
- ❌ missing from UCRM (never imported).
Per-status counts and per-currency totals are shown above the table. Matching
uses the payment's `providerName`/`providerPaymentId` (stamped on every payment
the plugin creates since v1.6.0) and falls back to amount + date + `Revolut: `
note matching for payments imported by older versions. The page UI is in
Bulgarian. The transfer list honors the "Revolut accounts to import from"
filter — it shows exactly what the plugin imports.

## Statement CSV import (full sender IBANs)
The Business API does not always expose the sender's IBAN for incoming
transfers, but Revolut's **account-statement CSV export** always does ("Sender
account" + "Sender name" columns). Upload the export via the **Account
statement CSV import** setting and run the plugin — every completed incoming
row is matched to a client by the sender IBAN (Paysera-grade), recorded with
its original date, and deduplicated against webhook/reconciliation imports by
transaction id. The file is processed once and re-processed only when its
content changes (upload a new export anytime).

**Manually entered payments are not duplicated:** when the matched client
already has a payment of the same amount on the same day (entered by hand
before the integration), the row is skipped and logged. Note the trade-off:
two genuinely separate equal-amount payments from the same client on the same
day would also be skipped — check the log lines for `skipped — client already
has` and add such payments manually if they ever occur.

## Importing past payments (backfill)
Set **Backfill history from date** in the configuration and run the plugin
(scheduled execution or *execute manually*). It imports all completed incoming
transfers from that date onward, paginating past Revolut's 1000-per-request
limit, keeping each payment's **original date** (`createdDate`), and never
duplicating already-imported transactions. Large backfills may span several
runs (Revolut's 60 req/min rate limit) — each run continues where the previous
stopped. The backfill runs once; change the date to run it again. Note: it
imports *all* incoming transfers in the period, so unrelated ones (e.g. card
acquirer top-ups) appear as unassigned payments to clean up manually.

## Security
- Webhooks are verified with HMAC-SHA256 (`Revolut-Signature`) and a 5-minute
  timestamp tolerance.
- The private key lives in `data/keys/private.pem` (web-denied, never shipped in
  the archive, gitignored).

## Notes / known limitations
- Revolut access token lives ~40 min; the refresh token is refreshed
  automatically. If refresh ever fails, clear the stored tokens and re-run the
  consent step.
- Revolut exposes the sender's IBAN only when the sender exists as a saved
  counterparty. For unknown external senders the plugin falls back to the leg
  description ("Payment from …") for the sender name, and to the counterparty's
  plain account number when an IBAN is absent — both feed the payment note and
  the client matching. When none of that is available, the note carries the
  payment reference only and the payment stays unassigned for manual linking.
