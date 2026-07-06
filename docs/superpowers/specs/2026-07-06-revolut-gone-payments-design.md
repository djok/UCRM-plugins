# Revolut Payments Import — Verified ⏭ Status + Re-import Action (v1.9.0, design)

**Date:** 2026-07-06
**Plugin:** `plugins/revolut-payments-import` (v1.8.0 → v1.9.0)
**Status:** approved by user (2026-07-06)

## Problem (reported from production use)

The user deleted unassigned plugin-created payments in UISP (after teaching the
client identities) and expected re-import. The plugin's idempotency store
(`processed.json`) still holds those transaction ids, so reconciliation
deliberately skips them — and the status page labels every
"processed-but-no-payment" row ⏭ „Пропуснат (ръчно плащане)", which is false
here: no manual payment exists, the payment is simply gone.

## Decisions (user-approved)

1. **Verify the ⏭ claim.** For a processed row with no matched plugin payment,
   the report resolves the expected client from the sender identity (same
   matching channel as everywhere: `ClientMatcher::findClientByIban(sender)`,
   memoized in the page) and searches the already-fetched month payments for a
   **manual** payment — same amount (±0.005), same `Y-m-d` date, NOT created by
   the plugin (`providerName !== 'Revolut'` AND note not starting `Revolut: `),
   and belonging to the resolved client when one resolves (any client when the
   sender is unresolvable).
   - Found → ⏭ `STATUS_SKIPPED` (row shows the manual payment's client).
   - Not found → new status `STATUS_GONE` „🗑 Обработено, но липсва" (row shows
     the *expected* client, i.e. where a re-import would land).
2. **Re-import is explicit, per row, admin-only.** A „Добави наново" button on
   each GONE row POSTs to `public.php` (`action=reimport`, `tx`, `token`,
   `month`): the handler verifies a UCRM admin session AND an HMAC token
   (`hash_hmac('sha256', 'reimport:'.$txId, signingSecret)`, compared with
   `hash_equals`), calls the new `IdempotencyStore::forget($txId)`, fetches the
   transaction fresh from Revolut, and runs it through the standard
   `EventProcessor` pipeline (original date via createdDate normalization,
   provider stamping, IBAN→name client matching). Then 303-redirects back to
   `?status=1&month=…&reimported=1`, which renders a success alert.
3. **Reconciliation never resurrects deleted payments on its own** — deletions
   stay respected unless the admin explicitly clicks re-import.
4. Behavior change accepted: rows that were ⏭ but have no verifiable manual
   payment become 🗑 GONE (the old blanket ⏭ was the reported bug).

## Components

| Unit | Change |
|---|---|
| `Support/IdempotencyStore.php` | new `forget(string $id): void` (unset + flush; no-op when absent) |
| `Status/StatusRow.php` | new const `STATUS_GONE = 'gone'` |
| `Status/MonthlyStatusReport.php` | `build()` gains optional 4th param `?callable $resolveClient = null` (fn(string $sender): ?int); processed-no-payment branch verifies via new private `findManualPayment()`; `summarize()` includes the fifth status |
| `public.php` | POST route `action=reimport` (before webhook fallthrough; webhook JSON POSTs have empty `$_POST` and pass through untouched); `handleReimportAction()`; `statusActionToken()`; status page: memoized resolver, GONE badge (`info`), per-row re-import form, success alert on `?reimported=1` |
| `manifest.json` / README / zip | version 1.9.0, doc section, repack |

## Security

- Action handler: UCRM admin gate first (403 otherwise), then token check —
  the HMAC binds the transaction id to the webhook signing secret, so only
  someone who loaded the admin status page holds a valid token (CSRF
  protection on a public endpoint with no session of its own). Missing
  signing secret → tokens are null → buttons not rendered, POSTs rejected.
- Errors never leak internals (log + generic Bulgarian page), as elsewhere.

## Testing

- IdempotencyStore: forget removes and persists; forgetting unknown id is a
  no-op that does not corrupt the store.
- MonthlyStatusReport: manual payment for resolved client → SKIPPED (client
  shown); no payment at all → GONE with expected client; manual payment of a
  different client → GONE; plugin-created payment same amount/date → does NOT
  count as manual (GONE); unresolved sender + any manual → SKIPPED; existing
  ⏭ tests updated to the new semantics; summarize gains the `gone` key.
- public.php via lint + full suite (web glue).

## Release

Manifest 1.9.0, README, zip rebuild.
