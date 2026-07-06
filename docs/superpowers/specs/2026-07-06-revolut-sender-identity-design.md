# Revolut Payments Import — Sender-Identity Matching (v1.8.0, design)

**Date:** 2026-07-06
**Plugin:** `plugins/revolut-payments-import` (v1.7.1 → v1.8.0)
**Status:** approved in discussion (user, 2026-07-06)

## Problem

Revolut's Business API does not expose the sender IBAN for incoming external
transfers (verified empirically: full `GET /transaction/{id}` dumps and the
v1.7.1 raw page show no IBAN and no counterparty; the statement CSV export has
`Sender account`/`Sender name` but is web-app-only — no API). Consequently all
live webhook payments are recorded unassigned. Invoice-number matching was
considered and rejected by the user: subscribers frequently quote wrong invoice
numbers, so the payment could be attached to the wrong client. The sender's
*identity* is the stable key.

## Concept (user-confirmed, Paysera model)

A client in UISP can hold multiple `bankAccounts[].accountNumber` entries
(free-text field). Matching happens in plugin code (`ClientMatcher`, a port of
paysera-payments-import's `find_client_by_account`): candidate string →
normalize → compare against every client's account numbers. The plugin will
now feed the **sender name** through this same channel when no IBAN is
available, so a sender name stored once on the client acts exactly like an
IBAN — recognized automatically, forever after. No checksums: the normalized
name is the key (readable, editable, debuggable).

## Decisions

1. **Matching order (webhook + reconciliation + statement):**
   sender IBAN (existing) → sender **name** (new fallback) → unassigned.
   The name goes through `ClientMatcher::findClientByIban()` unchanged —
   ONE normalization (`strtoupper`, strip all whitespace) on both sides.
   `Astreya 91 Ood` ⇔ stored `astreya 91 ood` ⇔ `ASTREYA91OOD` all match.
2. **Status page assist:** each „Записан без клиент"/„Липсва" row shows the
   sender name as a copyable key (the raw name; normalization equalizes).
   Admin pastes it once as a new Bank account on the client in UISP.
3. **Statement re-match (new):** on statement CSV upload, in addition to
   importing new rows, the importer walks rows whose transaction id maps to an
   **existing unassigned** UISP payment (lookup by `providerPaymentId`, then
   legacy note+amount+date heuristic), matches the client by `Sender account`
   IBAN (then sender name), and attaches the payment via
   `PATCH /payments/{id}` with `clientId`.
   - PATCH capability is unverified on UISP; implement with graceful
     degradation: on 4xx/405 log one clear message („UISP version does not
     support attaching payments via API") and continue. No deletion/recreation
     without explicit user approval later.
4. **Sender-identity learning (new, config-gated, default ON):** when the
   statement re-match (or a fresh statement import) identifies a client by
   real IBAN, the plugin adds to that client's `bankAccounts` the missing
   entries: the sender IBAN and the sender name. Update semantics: read the
   client, send existing entries + new ones, deduped by normalized
   accountNumber (safe under both replace and merge PATCH semantics).
   Config: checkbox `learnSenders` — "Learn sender identities from statement
   import"; absent/unchecked value treated as **enabled** unless explicitly
   disabled (checkbox semantics: value '1'/'on' = on; '0' = off; missing = on).
5. **No invoice-number matching** in this version (explicitly deprioritized by
   the user; may return later as an opt-in last-resort fallback).
6. **Diagnostics cleanup:** the sender-IBAN question is settled, so v1.8.0
   removes the temporary v1.7.1 `Webhook raw payload` log line and the
   `DEBUG transaction without sender IBAN` dump in EventProcessor. The
   admin-only `?raw` page stays (generally useful tool).

## Components

| Unit | Change |
|---|---|
| `Matching/ClientMatcher.php` | no code change: `findClientByIban()` already normalizes (uppercase, strip whitespace) both sides, so a sender name passes through the same entry point |
| `Webhook/EventProcessor.php` | `matchClient($sender['iban'])` → try IBAN, then name; note unchanged |
| `Statement/StatementImporter.php` | matching order IBAN → name for new rows; new re-match + learning pass for already-processed rows |
| `Ucrm/PaymentUpdater.php` (new) | `attachClient(int $paymentId, int $clientId): bool` via PATCH `payments/{id}`; graceful degradation |
| `Ucrm/ClientAccountLearner.php` (new) | `learn(int $clientId, list<string> $accountNumbers): void` — read client, append missing bankAccounts entries (deduped, normalized compare), PATCH back |
| `Ucrm/UcrmClient.php` + `SdkUcrmClient.php` | add `patch(string $endpoint, array $data): array` |
| `public.php` (status page) | copyable sender key on unassigned/missing rows |
| `Config/PluginConfig.php` | `learnSenders(): bool` |
| `manifest.json` | checkbox config option; version 1.8.0 |
| `main.php` | statement block passes the new collaborators |

## Data flow — statement re-match pass

For each parsed statement row (all rows, not only unprocessed ones):
1. If tx id NOT processed → existing new-import path (with the new name
   fallback after IBAN).
2. If tx id processed → find the UISP payment in a date-padded window
   (reuse month-window logic ±1 day around the row date):
   a. exact: `providerName='Revolut'` + `providerPaymentId` = tx id;
   b. else legacy heuristic (note prefix, amount ±0.005, same date).
   If found AND `clientId` is null:
   - resolve client: IBAN match → name match; if none, skip (log).
   - `PaymentUpdater::attachClient(...)`; on success + `learnSenders` →
     `ClientAccountLearner::learn(clientId, [senderIban, senderName])`.
   If found AND already assigned: learning only (config-gated), no attach.
3. Statement processing stays idempotent per file hash (`statementDone`),
   unchanged.

## Error handling

- PATCH failures: log with endpoint + HTTP code; never abort the whole run —
  continue with remaining rows.
- Learning failures: log and continue; learning is best-effort.
- All new UISP calls sit inside the existing statement try/catch in main.php.

## Testing

Unit tests (Docker `ucrm-php-test:8.3`):
- EventProcessor: name-fallback match (IBAN null → name matches client);
  IBAN takes precedence over name; both missing → unassigned.
- StatementImporter: new-row name fallback; re-match attaches unassigned
  payment (exact + heuristic lookup); assigned payment triggers learning only;
  attach failure degrades gracefully; learning dedupes existing entries.
- ClientAccountLearner: appends only missing entries; normalized dedupe;
  preserves existing entries in the PATCH body.
- PaymentUpdater: PATCH body shape; false on API exception.

## Release

Manifest 1.8.0, README section („Sender-identity matching"), zip rebuild.
