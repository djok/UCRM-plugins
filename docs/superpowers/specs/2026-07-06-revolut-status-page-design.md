# Revolut Payments Import — Monthly Status Page (design)

**Date:** 2026-07-06
**Plugin:** `plugins/revolut-payments-import` (v1.5.0 → v1.6.0)
**Status:** approved by user (2026-07-06)

## Goal

An admin-only page showing all incoming Revolut transfers for a selected month
(current, previous, or any of the last 12) and, for each transfer, whether it is
reconciled ("разнесен") in UISP/UCRM. UI language: **Bulgarian**.

## Decisions (user-confirmed)

1. **Statuses:** three-way visibility plus a skip marker (four badges total, see below).
2. **Scope:** incoming transfers only, restricted by the existing
   "Revolut accounts to import from" filter — exactly what the plugin imports.
3. **UI language:** Bulgarian.
4. **Exact matching going forward:** `UcrmPaymentGateway::record()` starts sending
   `providerName: 'Revolut'` + `providerPaymentId: <transaction id>` (fio_cz model).
   Existing payments (created without these fields) are matched heuristically.

## Approach

Live page in `public.php?status=1&month=YYYY-MM` (approach A). Follows the
existing `?accounts=1` pattern: `UcrmSecurity` admin check (403 for client-zone
or anonymous visitors), fetches on demand, renders server-side HTML. No cron
dependency; data is always current. Page load costs one Revolut transactions
sweep + one UCRM payments query (~1–3 s).

Rejected alternatives: cron-generated static report (stale data, pre-generation
per month, more state); local-only report from `processed.json` (store holds only
transaction ids — no amounts/dates to display).

## Data flow

1. **Month window:** `month` GET param (`YYYY-MM`, validated by regex; invalid or
   absent → current month). Window = `[YYYY-MM-01T00:00:00Z, min(now, end of month)]`.
2. **Revolut side:** `TransactionsApi::listAllTransactions(from, to)` filtered by
   the same rules as the import pipeline: `state == 'completed'`, `type` in
   `{transfer, topup}`, first leg with positive amount, account-id filter from
   config. Sender display info comes from the leg `description` and transaction
   `reference` — **no** per-transaction counterparty API calls (60 req/min limit
   would make the page crawl).
3. **UISP side:** `GET /payments?createdDateFrom=YYYY-MM-01&createdDateTo=<last day>`
   paginated with `limit`/`offset` (500 per page) until a short page is returned.
4. **Client names:** for matched payments with `clientId`, fetch `GET /clients`
   once and index by id (the matcher already pulls the full list elsewhere;
   dataset is an ISP client base — acceptable). Client link:
   `<ucrmPublicUrl>/client/<id>`.

## Matching (new class `Status\MonthlyStatusReport`)

Pure function over already-fetched data; no I/O — unit-testable.

Inputs: filtered Revolut transfers, UISP payments for the month, set of
processed transaction ids (from `IdempotencyStore`).

Per transfer, first match wins and **consumes** the payment (a payment can back
at most one transfer, so two equal-amount transfers cannot both match one
payment):

1. **Exact:** `payment.providerName === 'Revolut'` and
   `payment.providerPaymentId === transaction.id`.
2. **Heuristic (legacy payments):** note starts with `Revolut: `, same amount
   (±0.005), same `Y-m-d` date as the transfer's `completed_at`/`created_at`.

Statuses:

| Badge | Meaning | Condition |
|---|---|---|
| ✅ Разнесен | payment in UISP, attached to a client | matched payment with `clientId` |
| ⚠️ Записан без клиент | payment in UISP, unassigned | matched payment, `clientId` null |
| ⏭ Пропуснат (ръчно плащане) | dup-guard skipped the import because a manual same-day/same-amount payment exists | no matched payment, id **in** `processed.json` |
| ❌ Липсва | plugin never handled it | no matched payment, id **not in** `processed.json` |

Above the table: summary — count and amount per status, per currency.

## Components

| Unit | Responsibility |
|---|---|
| `src/Status/StatusRow.php` | immutable DTO: transaction id, date, amount, currency, sender text, reference, status const, client id/name |
| `src/Status/MonthlyStatusReport.php` | filtering + matching + totals; returns `list<StatusRow>` + summary array |
| `public.php` → `handleStatusPage()` | auth, month parsing, API fetches, HTML rendering (Bulgarian), month dropdown (last 12) + "Текущ"/"Предходен" quick links |
| `Ucrm/UcrmPaymentGateway.php` | add `providerName`/`providerPaymentId` to the POST body |

`renderPage()` in `public.php` is reused for the shell.

## Error handling

- Not configured (no refresh token): friendly Bulgarian message, no API calls.
- Revolut/UCRM API failure: log the exception to the plugin log, render
  "Проверете лога на плъгина" page with HTTP 500. Never leak exception text into
  the response (public endpoint).
- Invalid `month` param: silently fall back to the current month.

## Testing

- Unit tests for `MonthlyStatusReport`: transfer filtering (state/type/leg/account),
  exact match, heuristic match, payment consumption (two equal transfers, one
  payment), the ⏭ vs ❌ distinction, per-currency totals.
- Update `UcrmPaymentGatewayTest` for the new provider fields.
- Runner: Docker image `ucrm-php-test:8.3` (no local PHP).

## Release

Manifest version 1.6.0; README section "Monthly status page"; rebuild
`revolut-payments-import.zip` (repo convention).

## Amendment (2026-07-06, v1.7.0 — user-requested after v1.6.1)

The page is reachable from the UISP main menu and looks native (pattern:
`revenue-report` plugin):

- `manifest.json` gains `"menu": [{key: "Reports", label: "Revolut payments",
  type: "admin", target: "iframe"}]` — UISP shows the link in the Reporting
  section and opens `public.php` in an iframe inside the admin UI.
- The menu opens the URL without query parameters, so the status page became
  the **default GET page** of `public.php` (`?status=1` still works; OAuth
  `?code` and `?accounts` branches take precedence; webhooks are POST).
- Rendering switched to a UISP-look shell (`renderUispPage`): Lato from the
  UISP assets, Bootstrap 4.1.3 CDN (same tag as revenue-report), UISP-style
  header bar and `#edf0f3` background; summary as Bootstrap badges, table as
  `table table-sm table-hover` with `table-success/warning/secondary/danger`
  rows. Client links use `target="_top"` to escape the iframe. Error pages of
  the status flow use the same shell.
- v1.6.1 (interim hotfix, same day): `UcrmPaymentGateway` normalizes
  `createdDate` to second-precision UTC — UISP rejects the microsecond
  timestamps Revolut sends in webhook `completed_at` (400 Invalid datetime),
  which had silently blocked all live webhook payments since 2026-06-15.
