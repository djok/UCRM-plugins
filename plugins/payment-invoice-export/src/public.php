<?php

declare(strict_types=1);

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', '1');

use App\Service\ExportGenerator;
use App\Service\SalesReportGenerator;
use App\Service\ZipGenerator;
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

// Progress polling endpoint (AJAX)
if ($request->isMethod('GET') && $request->query->get('action') === 'progress') {
    header('Content-Type: application/json');
    $progressFile = __DIR__ . '/data/export-progress.json';
    if (file_exists($progressFile)) {
        readfile($progressFile);
    } else {
        echo json_encode(['step' => 0, 'total' => 1, 'message' => '']);
    }
    exit;
}

// Handle file download requests
if ($request->isMethod('GET') && $request->query->has('download')) {
    $filename = basename($request->query->get('download'));
    $dataDir = __DIR__ . '/data/exports';
    $filepath = $dataDir . '/' . $filename;

    if (file_exists($filepath) && strpos(realpath($filepath), realpath($dataDir)) === 0) {
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filepath));
        header('Cache-Control: max-age=0');
        readfile($filepath);
        unlink($filepath);
        exit;
    }
}

$renderer = new TemplateRenderer();
$page = $request->query->get('page', 'main');

// ============================================
// SETTINGS PAGE
// ============================================
if ($page === 'settings') {
    $saved = false;

    // Handle save mapping POST
    if ($request->isMethod('POST') && $request->request->get('action') === 'save_mapping') {
        $mapping = [
            'internetLabel' => trim($request->request->get('internetLabel', '')),
            'plans' => [],
            'surcharges' => [],
        ];
        $plans = $request->request->all('plans');
        if (is_array($plans)) {
            foreach ($plans as $planId => $label) {
                $mapping['plans'][(string)$planId] = trim($label);
            }
        }
        $surcharges = $request->request->all('surcharges');
        if (is_array($surcharges)) {
            foreach ($surcharges as $surchargeId => $label) {
                $mapping['surcharges'][(string)$surchargeId] = trim($label);
            }
        }
        saveServiceMapping($mapping);
        $saved = true;
    }

    // Load current mapping and service plans
    $mapping = loadServiceMapping();
    $servicePlans = $api->get('service-plans');
    $surcharges = $api->get('surcharges');

    // Sort plans: Internet first, then General
    usort($servicePlans, function ($a, $b) {
        $aType = ($a['servicePlanType'] ?? '') === 'Internet' ? 0 : 1;
        $bType = ($b['servicePlanType'] ?? '') === 'Internet' ? 0 : 1;
        if ($aType !== $bType) return $aType - $bType;
        return ($a['name'] ?? '') <=> ($b['name'] ?? '');
    });

    // Sort surcharges by name
    usort($surcharges, function ($a, $b) {
        return ($a['name'] ?? '') <=> ($b['name'] ?? '');
    });

    $renderer->render(
        __DIR__ . '/templates/settings.php',
        [
            'ucrmPublicUrl' => $ucrmOptions->ucrmPublicUrl,
            'servicePlans' => $servicePlans,
            'surcharges' => $surcharges,
            'mapping' => $mapping,
            'saved' => $saved,
        ]
    );
    exit;
}

// ============================================
// MAIN EXPORT PAGE
// ============================================
$controlsData = null;

// Process submitted export form
$isAjax = $request->headers->get('X-Requested-With') === 'XMLHttpRequest';

if ($request->isMethod('POST')) {
    try {
        clearProgress();
        writeProgress(0, 11, 'Стартиране на експорта...');
        $log->appendLog('Export started');

        $organizationId = $request->request->get('organization');
        $period = $request->request->get('period');
        $includePdfs = (bool) $request->request->get('includePdfs');

        // Validate required organization
        if (empty($organizationId)) {
            throw new \InvalidArgumentException('Organization is required');
        }

        $log->appendLog(sprintf('Organization: %s, Period: %s', $organizationId, $period));

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

        // Fetch supporting data
        writeProgress(1, 11, 'Зареждане на клиенти и настройки...');
        $log->appendLog('Fetching clients, payment methods, organizations and service plans...');
        $clients = $api->get('clients');
        $methods = $api->get('payment-methods');
        $organizations = $api->get('organizations');

        $log->appendLog(sprintf('Clients: %d, Methods: %d, Organizations: %d', count($clients), count($methods), count($organizations)));

        // Build service label map from saved mapping config
        $mapping = loadServiceMapping();
        $servicePlans = $api->get('service-plans');
        $internetPlanIds = [];
        $planLabelMap = []; // servicePlanId -> accounting label
        foreach ($servicePlans as $plan) {
            $planId = $plan['id'];
            $isInternet = (($plan['servicePlanType'] ?? '') === 'Internet');
            if ($isInternet) {
                $internetPlanIds[] = $planId;
            }
            // Check individual plan mapping first, then fallback to internet default
            $configuredLabel = $mapping['plans'][(string)$planId] ?? '';
            if ($configuredLabel !== '') {
                $planLabelMap[$planId] = $configuredLabel;
            } elseif ($isInternet && ($mapping['internetLabel'] ?? '') !== '') {
                $planLabelMap[$planId] = $mapping['internetLabel'];
            }
        }

        $clientServices = $api->get('clients/services');
        $serviceLabelMap = []; // clientServiceId -> accounting label
        foreach ($clientServices as $service) {
            $servicePlanId = $service['servicePlanId'] ?? null;
            if ($servicePlanId !== null && isset($planLabelMap[$servicePlanId])) {
                $serviceLabelMap[$service['id']] = $planLabelMap[$servicePlanId];
            }
        }
        $log->appendLog(sprintf('Service label mappings: %d', count($serviceLabelMap)));

        // Build surcharge label map from saved mapping config
        $surchargeLabelMap = []; // surchargeId -> accounting label
        $surchargeMapping = $mapping['surcharges'] ?? [];
        foreach ($surchargeMapping as $surchargeId => $label) {
            if ($label !== '') {
                $surchargeLabelMap[(int)$surchargeId] = $label;
            }
        }
        $log->appendLog(sprintf('Surcharge label mappings: %d', count($surchargeLabelMap)));

        // Get organization name for ZIP filename
        $organizationName = '';
        foreach ($organizations as $org) {
            if ((string)$org['id'] === (string)$organizationId) {
                $organizationName = $org['name'];
                break;
            }
        }

        // Build API query parameters
        $dateParams = [
            'createdDateFrom' => $startDate->format('Y-m-d'),
            'createdDateTo' => $endDate->format('Y-m-d'),
        ];

        // ============================================
        // PAYMENTS REPORT
        // ============================================
        writeProgress(2, 11, 'Зареждане на плащания...');
        $log->appendLog('Fetching payments...');
        $payments = $api->get('payments', $dateParams);
        $log->appendLog(sprintf('Found %d payments', count($payments)));

        // Filter by organization
        $payments = filterPaymentsByOrganization($api, $payments, (int) $organizationId);
        $log->appendLog(sprintf('After org filter: %d payments', count($payments)));

        // Enrich payments with invoice details
        writeProgress(3, 11, sprintf('Обработка на %d плащания...', count($payments)));
        $log->appendLog('Enriching with invoice details...');
        $paymentsWithInvoices = enrichPaymentsWithInvoiceDetails($api, $payments, $log);

        // ============================================
        // SALES REPORT (Invoices + Credit Notes)
        // ============================================
        writeProgress(4, 11, 'Зареждане на фактури...');
        $log->appendLog('Fetching invoices for sales report...');
        $invoiceParams = array_merge($dateParams, ['organizationId' => $organizationId]);
        $invoices = $api->get('invoices', $invoiceParams);
        $log->appendLog(sprintf('Found %d invoices', count($invoices)));

        // Filter out proforma invoices
        $invoices = array_filter($invoices, function ($invoice) {
            return !($invoice['proforma'] ?? false);
        });
        $invoices = array_values($invoices); // Re-index
        $log->appendLog(sprintf('After proforma filter: %d invoices', count($invoices)));

        // Enrich invoices with items and add document type markers
        $log->appendLog('Enriching invoices with line items...');
        $invoiceTotal = count($invoices);
        $invoiceIdx = 0;
        foreach ($invoices as &$invoice) {
            $invoiceIdx++;
            if ($invoiceIdx % 20 === 1 || $invoiceIdx === $invoiceTotal) {
                writeProgress(5, 11, sprintf('Обработка на фактури: %d / %d ...', $invoiceIdx, $invoiceTotal));
            }
            $invoice['_type'] = 'Фактура';
            $invoice['_docType'] = 1; // Plus-Minus document type for invoice
            try {
                $fullInvoice = $api->get('invoices/' . $invoice['id']);
                $invoice['items'] = $fullInvoice['items'] ?? [];
            } catch (\Exception $e) {
                $log->appendLog(sprintf('Could not fetch invoice items for %d: %s', $invoice['id'], $e->getMessage()));
                $invoice['items'] = [];
            }
        }
        unset($invoice);

        // Fetch credit notes
        writeProgress(6, 11, 'Зареждане на кредитни известия...');
        $log->appendLog('Fetching credit notes...');
        $creditNotes = $api->get('credit-notes', $invoiceParams);
        $log->appendLog(sprintf('Found %d credit notes', count($creditNotes)));

        // Enrich credit notes with items and add document type markers
        writeProgress(7, 11, sprintf('Обработка на %d кредитни известия...', count($creditNotes)));
        $log->appendLog('Enriching credit notes with line items...');
        foreach ($creditNotes as &$creditNote) {
            $creditNote['_type'] = 'Кредитно известие';
            $creditNote['_docType'] = 3; // Plus-Minus document type for credit note
            try {
                $fullCreditNote = $api->get('credit-notes/' . $creditNote['id']);
                $creditNote['items'] = $fullCreditNote['items'] ?? [];
            } catch (\Exception $e) {
                $log->appendLog(sprintf('Could not fetch credit note items for %d: %s', $creditNote['id'], $e->getMessage()));
                $creditNote['items'] = [];
            }
        }
        unset($creditNote);

        // Separate zero-total documents (excluded from sales export, included in controls)
        $zeroTotalInvoices = array_filter($invoices, function ($inv) {
            return (float)($inv['total'] ?? 0) == 0;
        });
        $zeroTotalInvoices = array_values($zeroTotalInvoices);

        $nonZeroInvoices = array_filter($invoices, function ($inv) {
            return (float)($inv['total'] ?? 0) != 0;
        });
        $nonZeroInvoices = array_values($nonZeroInvoices);

        $nonZeroCreditNotes = array_filter($creditNotes, function ($cn) {
            return (float)($cn['total'] ?? 0) != 0;
        });
        $nonZeroCreditNotes = array_values($nonZeroCreditNotes);

        $log->appendLog(sprintf('Zero-total invoices excluded from sales: %d', count($zeroTotalInvoices)));

        // ============================================
        // DOWNLOAD INVOICE PDFs
        // ============================================
        $tempDir = sys_get_temp_dir();
        $pdfFiles = [];
        $pdfInvoices = $includePdfs ? $invoices : $zeroTotalInvoices;
        $pdfFolder = $includePdfs ? 'fakturi' : 'nulevi_fakturi';
        $pdfLabel = $includePdfs ? 'фактури' : 'нулеви фактури';
        $pdfCount = count($pdfInvoices);
        if ($pdfCount > 0) {
            writeProgress(8, 11, sprintf('Изтегляне на PDF за %s: 0 / %d ...', $pdfLabel, $pdfCount));
            $log->appendLog(sprintf('Downloading PDFs for %d %s...', $pdfCount, $pdfLabel));
            foreach ($pdfInvoices as $idx => $inv) {
                if (($idx + 1) % 5 === 1 || ($idx + 1) === $pdfCount) {
                    writeProgress(8, 11, sprintf('Изтегляне на PDF за %s: %d / %d ...', $pdfLabel, $idx + 1, $pdfCount));
                }
                try {
                    $pdfContent = $api->get('invoices/' . $inv['id'] . '/pdf');
                    $safeNumber = preg_replace('/[^a-zA-Z0-9\-_]/u', '_', $inv['number'] ?? (string)$inv['id']);
                    $pdfPath = $tempDir . '/inv_pdf_' . $inv['id'] . '.pdf';
                    file_put_contents($pdfPath, $pdfContent);
                    $pdfFiles[] = [
                        'path' => $pdfPath,
                        'name' => $pdfFolder . '/' . $safeNumber . '.pdf',
                    ];
                } catch (\Exception $e) {
                    $log->appendLog(sprintf('Could not download PDF for invoice %d: %s', $inv['id'], $e->getMessage()));
                }
            }
            $log->appendLog(sprintf('Downloaded %d PDFs for %s', count($pdfFiles), $pdfLabel));
        }

        // Combine non-zero invoices and credit notes for sales export
        $salesDocuments = array_merge($nonZeroInvoices, $nonZeroCreditNotes);
        $log->appendLog(sprintf('Total sales documents (non-zero): %d', count($salesDocuments)));

        // ============================================
        // GENERATE REPORTS TO TEMP FILES
        // ============================================
        $timestamp = time();

        // Date format for filenames: D-M-YYYY
        $dateFromFormatted = sprintf('%d-%d-%s', (int)$startDate->format('d'), (int)$startDate->format('m'), $startDate->format('Y'));
        $dateToFormatted = sprintf('%d-%d-%s', (int)$endDate->format('d'), (int)$endDate->format('m'), $endDate->format('Y'));

        $files = [];

        // Generate Payments Report
        writeProgress(9, 11, 'Генериране на отчет за плащания...');
        $log->appendLog('Generating payments reports...');
        $paymentGenerator = new ExportGenerator($clients, $methods, $organizations);

        $paymentsCsvPath = $tempDir . '/payments_' . $timestamp . '.csv';
        $paymentsXlsxPath = $tempDir . '/payments_' . $timestamp . '.xlsx';

        $paymentGenerator->generateCsvToFile($paymentsCsvPath, $paymentsWithInvoices);
        $paymentGenerator->generateXlsxToFile($paymentsXlsxPath, $paymentsWithInvoices);

        $files[] = ['path' => $paymentsCsvPath, 'name' => "payments_{$dateFromFormatted}_{$dateToFormatted}.csv"];
        $files[] = ['path' => $paymentsXlsxPath, 'name' => "payments_{$dateFromFormatted}_{$dateToFormatted}.xlsx"];

        // Generate Sales Report
        writeProgress(10, 11, 'Генериране на отчет за продажби...');
        $log->appendLog('Generating sales reports...');
        $salesGenerator = new SalesReportGenerator($clients, $api, $serviceLabelMap, $surchargeLabelMap);

        $salesCsvPath = $tempDir . '/sales_' . $timestamp . '.csv';
        $salesXlsxPath = $tempDir . '/sales_' . $timestamp . '.xlsx';

        $salesGenerator->generateCsvToFile($salesCsvPath, $salesDocuments);
        $salesGenerator->generateXlsxToFile($salesXlsxPath, $salesDocuments);

        $files[] = ['path' => $salesCsvPath, 'name' => "sales_{$dateFromFormatted}_{$dateToFormatted}.csv"];
        $files[] = ['path' => $salesXlsxPath, 'name' => "sales_{$dateFromFormatted}_{$dateToFormatted}.xlsx"];

        // Generate Controls Report
        $log->appendLog('Generating controls report...');
        $controlsXlsxPath = $tempDir . '/controls_' . $timestamp . '.xlsx';
        generateControlsXlsx($controlsXlsxPath, $invoices, $creditNotes, $zeroTotalInvoices, $nonZeroInvoices);
        $files[] = ['path' => $controlsXlsxPath, 'name' => "controls_{$dateFromFormatted}_{$dateToFormatted}.xlsx"];

        // Add zero-total invoice PDFs to the archive
        $files = array_merge($files, $pdfFiles);

        // ============================================
        // CREATE ZIP AND SAVE TO DATA DIR
        // ============================================
        writeProgress(11, 11, 'Създаване на ZIP архив...');
        $log->appendLog('Creating ZIP archive...');

        // Sanitize organization name for filename
        $safeOrgName = preg_replace('/[^a-zA-Z0-9а-яА-ЯёЁ\-_\.\s]/u', '', $organizationName);
        $safeOrgName = str_replace(' ', '-', trim($safeOrgName));

        $zipFilename = sprintf(
            '%s_%s_%s_%d.zip',
            $safeOrgName,
            $startDate->format('Y-m-d'),
            $endDate->format('Y-m-d'),
            $timestamp
        );

        // Save ZIP to data/exports directory
        $exportsDir = __DIR__ . '/data/exports';
        if (!is_dir($exportsDir)) {
            mkdir($exportsDir, 0755, true);
        }
        // Clean old exports
        foreach (glob($exportsDir . '/*.zip') as $oldFile) {
            unlink($oldFile);
        }

        $zipPath = $exportsDir . '/' . $zipFilename;
        $zipGenerator = new ZipGenerator();
        $zipGenerator->createToFile($zipPath, $files);

        $log->appendLog('Export generated successfully');

        // Build controls data for display
        $invoiceNumbers = array_filter(array_map(function ($inv) {
            return $inv['number'] ?? '';
        }, $invoices));
        sort($invoiceNumbers);

        $missingNumbers = findMissingInvoiceNumbers($invoiceNumbers);

        // Calculate totals for exported (non-zero) invoices
        $exportedUntaxedSum = 0.0;
        $exportedVatSum = 0.0;
        foreach ($nonZeroInvoices as $inv) {
            $exportedUntaxedSum += (float)($inv['totalUntaxed'] ?? 0);
            $exportedVatSum += (float)($inv['totalTaxAmount'] ?? 0);
        }

        $controlsData = [
            'invoiceCount' => count($invoices),
            'creditNoteCount' => count($creditNotes),
            'minNumber' => !empty($invoiceNumbers) ? reset($invoiceNumbers) : '-',
            'maxNumber' => !empty($invoiceNumbers) ? end($invoiceNumbers) : '-',
            'missingNumbers' => $missingNumbers,
            'exportedUntaxedSum' => $exportedUntaxedSum,
            'exportedVatSum' => $exportedVatSum,
            'zeroTotalInvoices' => array_map(function ($inv) {
                $clientName = $inv['clientCompanyName'] ?? '';
                if (empty($clientName)) {
                    $clientName = trim(($inv['clientFirstName'] ?? '') . ' ' . ($inv['clientLastName'] ?? ''));
                }
                return [
                    'number' => $inv['number'] ?? '',
                    'date' => isset($inv['createdDate']) ? substr($inv['createdDate'], 0, 10) : '',
                    'clientName' => $clientName,
                    'eik' => $inv['clientCompanyRegistrationNumber'] ?? '',
                    'vatId' => $inv['clientCompanyTaxId'] ?? '',
                ];
            }, $zeroTotalInvoices),
            'downloadFile' => $zipFilename,
        ];

        clearProgress();

        // Return JSON for AJAX requests
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'data' => $controlsData]);
            exit;
        }

    } catch (\Throwable $e) {
        $log->appendLog(sprintf('ERROR: %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
        clearProgress();
        if ($isAjax) {
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
        throw $e;
    }
}

// Render export form
$renderer->render(
    __DIR__ . '/templates/form.php',
    [
        'organizations' => $api->get('organizations'),
        'ucrmPublicUrl' => $ucrmOptions->ucrmPublicUrl,
        'controlsData' => $controlsData,
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

/**
 * Generate controls XLSX with invoice summary and missing number detection.
 */
function generateControlsXlsx(string $filepath, array $invoices, array $creditNotes, array $zeroTotalInvoices = [], array $nonZeroInvoices = []): void
{
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

    // --- Sheet 1: Summary ---
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Обобщение');

    // Extract invoice numbers
    $invoiceNumbers = array_map(function ($inv) {
        return $inv['number'] ?? '';
    }, $invoices);
    $invoiceNumbers = array_filter($invoiceNumbers, function ($n) {
        return $n !== '';
    });
    sort($invoiceNumbers);

    $minNumber = !empty($invoiceNumbers) ? reset($invoiceNumbers) : '';
    $maxNumber = !empty($invoiceNumbers) ? end($invoiceNumbers) : '';

    // Summary headers + data
    $sheet->setCellValue('A1', 'Контрола');
    $sheet->setCellValue('B1', 'Стойност');
    $sheet->getStyle('A1:B1')->getFont()->setBold(true);
    $sheet->getStyle('A1:B1')->getFill()
        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()->setARGB('FFE0E0E0');

    $sheet->setCellValue('A2', 'Брой фактури');
    $sheet->setCellValue('B2', count($invoices));
    $sheet->setCellValue('A3', 'Най-малък номер');
    $sheet->setCellValue('B3', $minNumber);
    $sheet->setCellValue('A4', 'Най-голям номер');
    $sheet->setCellValue('B4', $maxNumber);
    $sheet->setCellValue('A5', 'Брой кредитни известия');
    $sheet->setCellValue('B5', count($creditNotes));
    $sheet->setCellValue('A6', 'Брой нулеви фактури');
    $sheet->setCellValue('B6', count($zeroTotalInvoices));

    // Totals for exported (non-zero) invoices
    $exportedUntaxedSum = 0.0;
    $exportedVatSum = 0.0;
    foreach ($nonZeroInvoices as $inv) {
        $exportedUntaxedSum += (float)($inv['totalUntaxed'] ?? 0);
        $exportedVatSum += (float)($inv['totalTaxAmount'] ?? 0);
    }

    $sheet->setCellValue('A8', 'Стойност без ДДС (експортирани)');
    $sheet->setCellValue('B8', round($exportedUntaxedSum, 2));
    $sheet->getStyle('B8')->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->setCellValue('A9', 'ДДС (експортирани)');
    $sheet->setCellValue('B9', round($exportedVatSum, 2));
    $sheet->getStyle('B9')->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->setCellValue('A10', 'Общо с ДДС (експортирани)');
    $sheet->setCellValue('B10', round($exportedUntaxedSum + $exportedVatSum, 2));
    $sheet->getStyle('B10')->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle('A8:B10')->getFont()->setBold(true);

    $sheet->getColumnDimension('A')->setAutoSize(true);
    $sheet->getColumnDimension('B')->setAutoSize(true);

    // --- Sheet 2: Missing invoice numbers ---
    $missingSheet = $spreadsheet->createSheet();
    $missingSheet->setTitle('Пропуснати номера');

    $missingSheet->setCellValue('A1', 'Пропуснат номер');
    $missingSheet->getStyle('A1')->getFont()->setBold(true);
    $missingSheet->getStyle('A1')->getFill()
        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()->setARGB('FFE0E0E0');

    $missingNumbers = findMissingInvoiceNumbers($invoiceNumbers);

    $row = 2;
    if (empty($missingNumbers)) {
        $missingSheet->setCellValue('A2', 'Няма пропуснати номера');
    } else {
        foreach ($missingNumbers as $missing) {
            $missingSheet->setCellValue('A' . $row, $missing);
            $row++;
        }
    }

    $missingSheet->getColumnDimension('A')->setAutoSize(true);

    // --- Sheet 3: Zero-total invoices ---
    $zeroSheet = $spreadsheet->createSheet();
    $zeroSheet->setTitle('Нулеви фактури');

    $zeroHeaders = ['Номер', 'Дата', 'Партньор', 'ЕИК', 'ИН по ДДС', 'Обща стойност с ДДС', 'ДДС'];
    foreach ($zeroHeaders as $ci => $header) {
        $zeroSheet->setCellValueByColumnAndRow($ci + 1, 1, $header);
    }
    $lastZeroCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($zeroHeaders));
    $zeroSheet->getStyle('A1:' . $lastZeroCol . '1')->getFont()->setBold(true);
    $zeroSheet->getStyle('A1:' . $lastZeroCol . '1')->getFill()
        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()->setARGB('FFE0E0E0');

    if (empty($zeroTotalInvoices)) {
        $zeroSheet->setCellValue('A2', 'Няма нулеви фактури');
    } else {
        $row = 2;
        foreach ($zeroTotalInvoices as $inv) {
            $zeroSheet->setCellValue('A' . $row, $inv['number'] ?? '');
            $date = $inv['createdDate'] ?? '';
            if ($date) {
                $datePart = substr($date, 0, 10);
                $parts = explode('-', $datePart);
                $date = (count($parts) === 3) ? sprintf('%s.%s.%s', $parts[2], $parts[1], $parts[0]) : $datePart;
            }
            $zeroSheet->setCellValue('B' . $row, $date);
            $clientName = $inv['clientCompanyName'] ?? '';
            if (empty($clientName)) {
                $clientName = trim(($inv['clientFirstName'] ?? '') . ' ' . ($inv['clientLastName'] ?? ''));
            }
            $zeroSheet->setCellValue('C' . $row, $clientName);
            $zeroSheet->setCellValue('D' . $row, $inv['clientCompanyRegistrationNumber'] ?? '');
            $zeroSheet->setCellValue('E' . $row, $inv['clientCompanyTaxId'] ?? '');
            $zeroSheet->setCellValue('F' . $row, (float)($inv['total'] ?? 0));
            $zeroSheet->setCellValue('G' . $row, (float)($inv['totalTaxAmount'] ?? 0));
            $row++;
        }
    }

    foreach (range('A', $lastZeroCol) as $col) {
        $zeroSheet->getColumnDimension($col)->setAutoSize(true);
    }

    // Save
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save($filepath);
}

/**
 * Find missing invoice numbers between min and max in a sorted list.
 */
function findMissingInvoiceNumbers(array $invoiceNumbers): array
{
    $numericParts = [];
    $prefixes = [];
    foreach ($invoiceNumbers as $num) {
        if (preg_match('/^(\D*)(\d+)$/', $num, $matches)) {
            $prefix = $matches[1];
            $numericPart = (int)$matches[2];
            $numericParts[] = $numericPart;
            $prefixes[$prefix] = true;
        }
    }

    $missingNumbers = [];
    if (count($numericParts) >= 2) {
        sort($numericParts);
        $min = reset($numericParts);
        $max = end($numericParts);
        $existingSet = array_flip($numericParts);
        $prefix = count($prefixes) === 1 ? array_key_first($prefixes) : '';

        for ($i = $min; $i <= $max; $i++) {
            if (!isset($existingSet[$i])) {
                $digitCount = strlen((string)$max);
                $missingNumbers[] = $prefix . str_pad((string)$i, $digitCount, '0', STR_PAD_LEFT);
            }
        }
    }

    return $missingNumbers;
}

/**
 * Load service name mapping from data/service-mapping.json.
 */
function loadServiceMapping(): array
{
    $path = __DIR__ . '/data/service-mapping.json';
    if (!file_exists($path)) {
        return ['internetLabel' => '', 'plans' => [], 'surcharges' => []];
    }

    $data = json_decode(file_get_contents($path), true);
    if (!is_array($data)) {
        return ['internetLabel' => '', 'plans' => [], 'surcharges' => []];
    }

    return [
        'internetLabel' => $data['internetLabel'] ?? '',
        'plans' => $data['plans'] ?? [],
        'surcharges' => $data['surcharges'] ?? [],
    ];
}

/**
 * Save service name mapping to data/service-mapping.json.
 */
function saveServiceMapping(array $mapping): void
{
    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    file_put_contents(
        $dir . '/service-mapping.json',
        json_encode($mapping, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
}

/**
 * Write export progress to a JSON file for AJAX polling.
 */
function writeProgress(int $step, int $total, string $message): void
{
    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents(
        $dir . '/export-progress.json',
        json_encode(['step' => $step, 'total' => $total, 'message' => $message])
    );
}

/**
 * Clean up the progress file.
 */
function clearProgress(): void
{
    $path = __DIR__ . '/data/export-progress.json';
    if (file_exists($path)) {
        unlink($path);
    }
}
