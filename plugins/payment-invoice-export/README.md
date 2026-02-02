# Payment Invoice CSV Export

This UCRM/UISP plugin exports payments with their linked invoice details to CSV format. It shows which invoices were paid with each payment and how much was applied to each invoice.

## Features

- Export payments for configurable time periods
- Predefined periods: Current/Previous Month, Quarter, Year
- Custom date range support
- Filter by organization
- One row per payment-invoice combination for easy accounting import
- Includes payment method, currency, credit amounts, and notes

## CSV Columns

| Column | Description |
|--------|-------------|
| Payment ID | Unique payment identifier |
| Payment Date | Date the payment was created |
| Client ID | Client identifier |
| Client Name | Full client name (First + Last) |
| Company Name | Company name if applicable |
| Payment Method | Payment method name |
| Currency | Payment currency code |
| Total Payment Amount | Full payment amount |
| Credit Amount | Amount applied as client credit |
| Invoice ID | Linked invoice identifier |
| Invoice Number | Invoice number |
| Invoice Date | Invoice creation date |
| Invoice Total | Full invoice amount |
| Invoice Status | Invoice status (Paid/Unpaid/etc.) |
| Amount Applied to Invoice | Amount from this payment applied to the invoice |
| Note | Payment note |

## Installation

1. Download or clone the plugin
2. Navigate to the `src` directory and run `composer install`
3. Create a ZIP archive of the `src` directory contents
4. Upload the ZIP to UCRM/UISP via System > Plugins

## Building

```bash
cd plugins/payment-invoice-export/src
composer install --no-dev
cd ..
zip -r payment-invoice-export.zip src/
```

## Requirements

- UCRM 2.14.0+ or UISP 2.1.0+
- Admin user with Billing Invoices view permission

## Usage

1. Navigate to **Reports > Payment Invoice Export** in UCRM/UISP
2. Select an organization (or "All Organizations")
3. Choose a period or select "Custom Range" for specific dates
4. Click "Export CSV"

## Notes

- If a payment covers multiple invoices, there will be one row per invoice
- Payments with only credit (no invoice) will show empty invoice columns
- Invoice lookups are cached to optimize performance
