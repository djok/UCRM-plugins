<?php

declare(strict_types=1);

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', '1');

use App\Service\ExportGenerator;
use App\Service\DatePeriodCalculator;
use App\Service\TemplateRenderer;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Ubnt\UcrmPluginSdk\Security\PermissionNames;
use Ubnt\UcrmPluginSdk\Service\UcrmApi;
use Ubnt\UcrmPluginSdk\Service\UcrmOptionsManager;
use Ubnt\UcrmPluginSdk\Service\UcrmSecurity;
use Ubnt\UcrmPluginSdk\Service\PluginLogManager;

chdir(__DIR__);

require __DIR__ . '/vendor/autoload.php';

$log = PluginLogManager::create();

// Retrieve API connection.
$api = UcrmApi::create();

// Ensure that user is logged in and has permission to view invoices/payments.
$security = UcrmSecurity::create();
$user = $security->getUser();
if (! $user || $user->isClient || ! $user->hasViewPermission(PermissionNames::BILLING_INVOICES)) {
    \App\Http::forbidden();
}

$optionsManager = UcrmOptionsManager::create();
$ucrmOptions = $optionsManager->loadOptions();
$request = Request::createFromGlobals();

// Process submitted form.
if ($request->isMethod('POST')) {
    try {
        $log->appendLog('Export started');

        $organizationId = $request->request->get('organization');
        $period = $request->request->get('period');

        $log->appendLog(sprintf('Organization: %s, Period: %s', $organizationId ?: 'all', $period));

        // Calculate date range from period or custom dates
        $dateCalculator = new DatePeriodCalculator();

        if ($period === 'custom') {
            $dateFrom = $request->request->get('dateFrom');
            $dateTo = $request->request->get('dateTo');
            [$startDate, $endDate] = $dateCalculator->getCustomRange($dateFrom, $dateTo);
        } else {
            [$startDate, $endDate] = $dateCalculator->calculatePeriod($period ?? 'current_month');
        }

        $log->appendLog(sprintf('Date range: %s to %s', $startDate->format('Y-m-d'), $endDate->format('Y-m-d')));

        // Build API query parameters for payments
        $parameters = [
            'createdDateFrom' => $startDate->format('Y-m-d'),
            'createdDateTo' => $endDate->format('Y-m-d'),
        ];

        // Fetch payments from API
        $log->appendLog('Fetching payments...');
        $payments = $api->get('payments', $parameters);
        $log->appendLog(sprintf('Found %d payments', count($payments)));

        // Log payment fields for debugging
        if (!empty($payments)) {
            $log->appendLog('Payment fields: ' . implode(', ', array_keys($payments[0])));
        }

        // Filter by organization if selected
        if (!empty($organizationId)) {
            $payments = filterPaymentsByOrganization($api, $payments, (int) $organizationId);
            $log->appendLog(sprintf('After org filter: %d payments', count($payments)));
        }

        // Enrich payments with invoice details
        $log->appendLog('Enriching with invoice details...');
        $paymentsWithInvoices = enrichPaymentsWithInvoiceDetails($api, $payments, $log);

        // Fetch supporting data for CSV generation
        $log->appendLog('Fetching clients, payment methods and organizations...');
        $clients = $api->get('clients');
        $methods = $api->get('payment-methods');
        $organizations = $api->get('organizations');

        $log->appendLog(sprintf('Clients: %d, Methods: %d, Organizations: %d', count($clients), count($methods), count($organizations)));

        // Get export format
        $format = $request->request->get('format', 'csv');
        $log->appendLog(sprintf('Export format: %s', $format));

        // Generate and download file
        $exportGenerator = new ExportGenerator($clients, $methods, $organizations);
        $baseFilename = sprintf('payments-with-invoices-%s-to-%s', $startDate->format('Y-m-d'), $endDate->format('Y-m-d'));

        if ($format === 'xlsx') {
            $log->appendLog('Generating XLSX...');
            $exportGenerator->generateXlsx($baseFilename . '.xlsx', $paymentsWithInvoices);
        } else {
            $log->appendLog('Generating CSV...');
            $exportGenerator->generateCsv($baseFilename . '.csv', $paymentsWithInvoices);
        }

        $log->appendLog('Export generated successfully');
        exit;

    } catch (\Throwable $e) {
        $log->appendLog(sprintf('ERROR: %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
        throw $e;
    }
}

// Render form.
$renderer = new TemplateRenderer();
$renderer->render(
    __DIR__ . '/templates/form.php',
    [
        'organizations' => $api->get('organizations'),
        'ucrmPublicUrl' => $ucrmOptions->ucrmPublicUrl,
    ]
);

/**
 * Filter payments by organization (via client lookup).
 */
function filterPaymentsByOrganization($api, array $payments, int $organizationId): array
{
    // Get clients for this organization
    $clients = $api->get('clients', ['organizationId' => $organizationId]);
    $clientIds = array_column($clients, 'id');

    return array_filter($payments, function ($payment) use ($clientIds) {
        return in_array($payment['clientId'], $clientIds, true);
    });
}

/**
 * Enrich payments with invoice details from paymentCovers.
 */
function enrichPaymentsWithInvoiceDetails($api, array $payments, $log = null): array
{
    $invoiceCache = [];

    foreach ($payments as &$payment) {
        $payment['invoiceDetails'] = [];

        if (!empty($payment['paymentCovers'])) {
            foreach ($payment['paymentCovers'] as $cover) {
                $invoiceId = $cover['invoiceId'] ?? null;

                if ($invoiceId === null) {
                    continue;
                }

                // Cache invoice lookups to avoid redundant API calls
                if (!isset($invoiceCache[$invoiceId])) {
                    try {
                        $invoiceCache[$invoiceId] = $api->get('invoices/' . $invoiceId);
                    } catch (\Exception $e) {
                        // Invoice might have been deleted
                        if ($log) {
                            $log->appendLog(sprintf('Could not fetch invoice %d: %s', $invoiceId, $e->getMessage()));
                        }
                        $invoiceCache[$invoiceId] = null;
                    }
                }

                $invoice = $invoiceCache[$invoiceId];

                $payment['invoiceDetails'][] = [
                    'invoiceId' => $invoiceId,
                    'invoiceNumber' => $invoice['number'] ?? '',
                    'invoiceDate' => isset($invoice['createdDate']) ? substr($invoice['createdDate'], 0, 10) : '',
                    'invoiceTotal' => $invoice['total'] ?? 0,
                    'invoiceStatus' => formatInvoiceStatus($invoice['status'] ?? -1),
                    'amountCovered' => $cover['amount'] ?? 0,
                ];
            }
        }
    }

    return $payments;
}

/**
 * Format invoice status code to human-readable string.
 */
function formatInvoiceStatus(int $status): string
{
    switch ($status) {
        case 0:
            return 'Draft';
        case 1:
            return 'Unpaid';
        case 2:
            return 'Partially paid';
        case 3:
            return 'Paid';
        case 4:
            return 'Void';
        default:
            return 'Unknown';
    }
}
