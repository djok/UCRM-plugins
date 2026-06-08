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
   the **OAuth redirect URI** to your UCRM URL (e.g. `https://your-ucrm.example.com`),
   and copy the issued **ClientID**.
3. **Configure the plugin:** set *Environment* (Sandbox/Production), *ClientID*,
   *OAuth redirect URI* (same domain as above), and *UCRM payment method name*
   (an existing UCRM payment method, e.g. `Bank transfer`). Save.
4. **Authorize:** the log prints a consent URL. Open it, authorize access, then
   copy the `code` query parameter from the redirect URL into the
   *Authorization code* field and Save. The plugin exchanges it for tokens and
   registers the webhook automatically.
5. Done — incoming payments now appear in UCRM in real time. The cron run
   (`main.php`) refreshes the token and reconciles missed events.

## How matching works
For each completed incoming transfer the plugin fetches the counterparty
(`GET /counterparty/{id}`) to obtain the sender's IBAN, then matches it against
the UCRM client's bank accounts. If the IBAN is missing or matches no client,
the payment is recorded **unassigned** (no client) so it can be reconciled
manually.

## Security
- Webhooks are verified with HMAC-SHA256 (`Revolut-Signature`) and a 5-minute
  timestamp tolerance.
- The private key lives in `data/keys/private.pem` (web-denied, never shipped in
  the archive, gitignored).

## Notes / known limitations
- Revolut access token lives ~40 min; the refresh token is refreshed
  automatically. If refresh ever fails, clear the stored tokens and re-run the
  consent step.
- If a counterparty has no IBAN (some external senders), matching falls back to
  unassigned. Consider a future enhancement to also match by payment reference.
