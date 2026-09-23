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
Open **Reporting → Revolut payments** in the UISP main menu (since v1.7.0 the
plugin registers a menu item and the page renders in the UISP look inside the
admin UI), or open the plugin's Public URL directly — the status page is its
default GET page; `?month=YYYY-MM` selects a month. UCRM admin login required.
The page shows every incoming Revolut transfer for a selected month — current
by default, any of the last 12 via the dropdown — with its status in UCRM/UISP:
- ✅ recorded and attached to a client (linked),
- ⚠️ recorded but unassigned (waiting for manual attachment),
- ⏭ skipped because the matched client really has a manual payment with the
  same amount and date (verified against the month's payments),
- 🗑 processed in the past but the payment no longer exists in UISP (deleted
  or lost) — the row shows the client it would be attached to and offers an
  admin-only **„Добави наново"** button that re-imports the transaction from
  Revolut with its original date and automatic sender matching,
- ❌ missing from UCRM (never imported).
Deleted payments are never re-imported automatically — only via the button.
Re-import is duplicate-safe (v1.10.3): the button's "already imported?" check
and the import run under one server-side lock, so a double-click, two admins or
a replayed request can never create two payments — the second request waits,
sees the payment the first one created and does nothing. The transaction also
stays in the plugin's processed history the whole time (the re-import bypasses
only that check), so the webhook, the scheduled run and the statement import
keep skipping it and cannot record it concurrently; if a re-import fails, the
row simply stays re-importable.
Per-status counts and per-currency totals are shown above the table. Matching
is exact by the payment's transaction key (see *Stable payment key* below) and
falls back to amount + date + `Revolut: ` note matching only for payments
imported before v1.10.2 that carry no key. The page UI is in
Bulgarian. The transfer list honors the "Revolut accounts to import from"
filter — it shows exactly what the plugin imports.

## Sender-identity matching (no-IBAN transfers)
Revolut's API exposes no sender IBAN for external incoming transfers, so the
plugin also matches by the **sender's name**, fed through the same
bank-account matching as IBANs: add the sender name (as shown on the status
page — use the ⧉ copy icon) as an extra *Bank account* entry on the client in
UISP, and every future transfer from that sender is attached automatically.
A client can hold any number of such entries (IBANs and names mixed);
matching ignores case and whitespace.

**Statement upload attaches and learns.** When you upload an account
statement CSV, rows whose transaction was already imported get a second pass:
if the payment is still unassigned, the plugin recognizes the client by the
statement's `Sender account` IBAN (or sender name) and attaches the payment.
With **Learn sender identities** enabled (default), it also stores the sender
IBAN + name on the recognized client — one statement upload teaches the
plugin to auto-match all your senders from then on. Re-processing happens
only when the uploaded file's content changes, so upload a fresh export to
re-run. If your UISP version rejects attaching payments via API, the log
says so per payment and everything else still works.

**Names are not unique.** Two clients whose senders share a name (common with
personal names) would match whichever client the API lists first — verify
before storing a common name as an identity, and prefer the IBAN entry from
the statement when available. Learned identities are plain Bank-account rows
on the client, so any mistake is visible and fixable right in UISP.

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
already has a payment of the same amount on the same day — entered by hand, or
this very transfer's own payment — the row is skipped and logged. Payments the
plugin created for *other* transfers do not count (v1.10.3; they are told apart
by their transaction key), so a client who pays the same amount twice in one
day gets both payments. The remaining trade-off is with manual entries only: a
genuine transfer on the same day and for the same amount as a hand-entered
payment is skipped — check the log lines for `skipped — client` and add such a
payment manually if it ever occurs.

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

## Stable payment key (v1.10.2)
Every payment the plugin creates ends its note with the Revolut transaction id
as a final segment, e.g. `Revolut: ACME LTD | INV 7 | BG00… | tx:055d7bd0-…`
(or `Revolut: tx:…` when nothing else is known). This key links the UISP
payment to its transaction exactly: the status page, the statement re-matcher
and the re-import button's duplicate guard all match on it.

Why the note: UISP 4.5.33 accepts `providerName`/`providerPaymentId` on
`POST payments` but does **not** persist them — they read back `null` — so
matching on them never worked, and the re-import guard could not detect a
double-submit. The note is a persisted text column (no length limit). A UISP
*payment custom attribute* was the alternative: the API supports it (and can
even filter payments by it), but it needs a new global attribute definition
created at runtime that an administrator could delete or rename, and it could
not be verified on the live system without writing to it. The provider fields
are still sent and still honoured should a UISP version persist them.

Only a Revolut transaction id (UUID-shaped, as in both the API and the
statement CSV) forms a key, and only as the whole final segment after `|` or
the `Revolut:` prefix — a legacy bank reference such as `Ref: tx:12345` is never
mistaken for one.

Payments imported before v1.10.2 have no key; they keep matching through the
amount + date + `Revolut: ` note heuristic (ambiguous when a client pays the
same amount twice on one day). Since v1.10.2 that heuristic compares the **UTC**
date of the payment — UISP returns `createdDate` in the server's local time, so
a transfer completed near midnight UTC used to look "missing" and could be
re-imported as a duplicate. The re-import guard never uses the heuristic, so it
cannot block a legitimate re-import. Do not remove the `tx:` segment when
editing a note — the payment then falls back to the heuristic.

## Resilience when Revolut is unavailable
A blocked/restricted Revolut account, an expired authorization, or a Revolut
outage no longer takes down the whole integration (v1.10.0):
- **Isolated cron stages.** `main.php` runs token refresh, failed-event replay,
  reconciliation and the statement-CSV import as independent stages. A Revolut
  failure (401/403/429/5xx, or the `failed-events` 500 Revolut sometimes
  returns) fails only its own stage — the **statement CSV import still runs**,
  since it needs only UISP and the uploaded file.
- **Structured, redacted errors.** Every Revolut call maps transport failures to
  a `RevolutApiException` carrying the HTTP status and Revolut error code; the
  log line names the endpoint and status without the query string or token.
- **Clear re-authorization signal.** If the stored refresh token is rejected
  (HTTP 400/401), the log says to clear the *Refresh token (managed)* field and
  re-open the consent URL. The token is never auto-cleared on a transient error.
- **Degraded status page.** When Revolut is unreachable the monthly page shows
  the UISP-side data plus a banner (last successful sync time / a
  re-authorization notice / a non-`active` account warning) instead of a blank
  500. Webhook deliveries that cannot be authoritatively processed return HTTP
  503 so Revolut retries and parks them in failed-events for later replay,
  rather than being silently acknowledged and dropped.
- **Concurrency-safe recording (v1.10.3).** The webhook, the scheduled run
  and the statement import are separate processes that can reach the same new
  transfer at the same moment. Recording is serialised by a shared lock
  (`data/import.lock`): under it the processed history is re-read from disk and
  the payment is recorded only if no other process has done so. The history
  file (`data/processed.json`) is read under a shared lock, so a reader never
  sees it half-rewritten; a corrupt history now stops the run with an error in
  the log instead of silently reading as "nothing processed" (which would
  import everything again) or being overwritten.
- **Terminal transaction states.** `declined`/`failed`/`reverted` transfers are
  recognized as terminal (no payment, no endless "waiting for completion" log
  spam). A transfer that is `reverted` **after** its payment was recorded logs a
  clear warning and is flagged on the status page — reverse that payment by hand.

- **Internal transfers are never payments (v1.10.1).** A transaction that
  moves money between your own Revolut accounts — it carries a negative leg on
  the account the money left — is ignored. This covers Revolut's hold/"Release"
  movements during an account seizure, pocket moves and exchanges. (Before
  v1.10.1 each "Release" of held funds was recorded as a new unassigned
  payment, although the funds had already been imported when they first
  arrived.) Unassigned payments are attached via `PATCH payments/{id}/attach`
  — UISP rejects `clientId` on a plain `PATCH payments/{id}`.

Note: if Revolut has **blocked the money flow** on the account, the API reports
zero incoming transactions and the account may still read `state=active`; the
plugin then correctly imports nothing. That is an account/compliance matter to
resolve with Revolut support — the integration resumes on its own once transfers
flow again (keep the scheduled execution enabled so reconciliation catches up).

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
