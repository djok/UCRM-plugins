<?php

declare(strict_types=1);

namespace App\Service;

use League\Csv\Writer;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use SplTempFileObject;

class ExportGenerator
{
    private array $clientMap;
    private array $methodMap;
    private array $organizationMap;

    public function __construct(array $clients, array $methods, array $organizations)
    {
        $this->clientMap = $this->buildClientMap($clients);
        $this->methodMap = $this->buildMethodMap($methods);
        $this->organizationMap = $this->buildOrganizationMap($organizations);
    }

    public function generateCsv(string $filename, array $payments): void
    {
        $csv = Writer::createFromFileObject(new SplTempFileObject());

        $csv->insertOne($this->getHeaderLine());

        foreach ($payments as $payment) {
            $rows = $this->getPaymentRows($payment);
            foreach ($rows as $row) {
                $csv->insertOne($row);
            }
        }

        $csv->download($filename);
    }

    /**
     * Generate CSV file to disk with Windows-1251 encoding and semicolon delimiter.
     */
    public function generateCsvToFile(string $filepath, array $payments): void
    {
        $csv = Writer::createFromFileObject(new SplTempFileObject());
        $csv->setDelimiter(';');

        $csv->insertOne($this->getHeaderLine());

        foreach ($payments as $payment) {
            $rows = $this->getPaymentRows($payment);
            foreach ($rows as $row) {
                $csv->insertOne($row);
            }
        }

        // Convert to Windows-1251, strip CSV enclosure quotes
        $content = $csv->toString();
        $content = str_replace('"', '', $content);
        $convertedContent = iconv('UTF-8', 'WINDOWS-1251//TRANSLIT', $content);
        file_put_contents($filepath, $convertedContent);
    }

    public function generateXlsx(string $filename, array $payments): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Payments');

        // Set headers
        $headers = $this->getHeaderLine();
        $col = 1;
        foreach ($headers as $header) {
            $sheet->setCellValueByColumnAndRow($col, 1, $header);
            $col++;
        }

        // Style headers
        $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle('A1:' . $lastCol . '1')->getFont()->setBold(true);
        $sheet->getStyle('A1:' . $lastCol . '1')->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFE0E0E0');

        // Add data rows
        $rowNum = 2;
        foreach ($payments as $payment) {
            $rows = $this->getPaymentRows($payment);
            foreach ($rows as $row) {
                $col = 1;
                foreach ($row as $value) {
                    $sheet->setCellValueByColumnAndRow($col, $rowNum, $value);
                    $col++;
                }
                $rowNum++;
            }
        }

        // Auto-size columns
        foreach (range('A', $lastCol) as $columnID) {
            $sheet->getColumnDimension($columnID)->setAutoSize(true);
        }

        // Output file
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
    }

    /**
     * Generate XLSX file to disk.
     */
    public function generateXlsxToFile(string $filepath, array $payments): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Payments');

        // Set headers
        $headers = $this->getHeaderLine();
        $col = 1;
        foreach ($headers as $header) {
            $sheet->setCellValueByColumnAndRow($col, 1, $header);
            $col++;
        }

        // Style headers
        $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle('A1:' . $lastCol . '1')->getFont()->setBold(true);
        $sheet->getStyle('A1:' . $lastCol . '1')->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFE0E0E0');

        // Add data rows
        $rowNum = 2;
        foreach ($payments as $payment) {
            $rows = $this->getPaymentRows($payment);
            foreach ($rows as $row) {
                $col = 1;
                foreach ($row as $value) {
                    $sheet->setCellValueByColumnAndRow($col, $rowNum, $value);
                    $col++;
                }
                $rowNum++;
            }
        }

        // Auto-size columns
        foreach (range('A', $lastCol) as $columnID) {
            $sheet->getColumnDimension($columnID)->setAutoSize(true);
        }

        // Save to file
        $writer = new Xlsx($spreadsheet);
        $writer->save($filepath);
    }

    private function getHeaderLine(): array
    {
        return [
            'Organization',
            'Payment ID',
            'Provider Payment ID',
            'Payment Date',
            'Client ID',
            'Client Type',
            'Client Name',
            'Company ID (EIK)',
            'VAT ID',
            'Personal ID',
            'Payment Method',
            'Currency',
            'Total Payment Amount',
            'Credit Amount',
            'Invoice Number',
            'Invoice Date',
            'Invoice Total',
            'Invoice Status',
            'Amount Applied to Invoice',
            'Note',
        ];
    }

    private function getPaymentRows(array $payment): array
    {
        $rows = [];
        $client = $this->clientMap[$payment['clientId']] ?? [];
        $clientName = $this->formatClientName($client);
        $clientType = $this->formatClientType($client);
        $companyId = $client['companyRegistrationNumber'] ?? '';
        $vatId = $client['companyTaxId'] ?? '';
        $personalId = $client['userIdent'] ?? '';
        $methodName = $this->methodMap[$payment['methodId'] ?? ''] ?? 'Unknown';

        // Get organization name from client's organizationId
        $organizationId = $client['organizationId'] ?? null;
        $organizationName = $this->organizationMap[$organizationId] ?? '';

        $baseRow = [
            $organizationName,
            $payment['id'],
            $payment['providerPaymentId'] ?? '',
            $this->formatDate($payment['createdDate'] ?? ''),
            $payment['clientId'] ?? '',
            $clientType,
            $clientName,
            $companyId,
            $vatId,
            $personalId,
            $methodName,
            $payment['currencyCode'] ?? '',
            $payment['amount'] ?? 0,
            $payment['creditAmount'] ?? 0,
        ];

        // If payment has invoice details, create one row per invoice
        if (!empty($payment['invoiceDetails'])) {
            foreach ($payment['invoiceDetails'] as $invoiceDetail) {
                $rows[] = array_merge($baseRow, [
                    $invoiceDetail['invoiceNumber'] ?? '',
                    $invoiceDetail['invoiceDate'] ?? '',
                    $invoiceDetail['invoiceTotal'] ?? '',
                    $invoiceDetail['invoiceStatus'] ?? '',
                    $invoiceDetail['amountCovered'] ?? '',
                    $payment['note'] ?? '',
                ]);
            }
        } else {
            // Payment with no linked invoices (credit only)
            $rows[] = array_merge($baseRow, [
                '', // Invoice Number
                '', // Invoice Date
                '', // Invoice Total
                '', // Invoice Status
                '', // Amount Applied
                $payment['note'] ?? '',
            ]);
        }

        return $rows;
    }

    private function formatClientType(array $client): string
    {
        if (empty($client)) {
            return '';
        }

        // clientType: 1 = Residential (private person), 2 = Commercial (company)
        $type = $client['clientType'] ?? 0;

        if ($type === 1) {
            return 'Private Person';
        } elseif ($type === 2) {
            return 'Company';
        }

        return 'Unknown';
    }

    private function formatClientName(array $client): string
    {
        if (empty($client)) {
            return '';
        }

        // clientType: 1 = Residential, 2 = Commercial
        if (($client['clientType'] ?? 0) === 1) {
            return trim(($client['firstName'] ?? '') . ' ' . ($client['lastName'] ?? ''));
        }

        // Commercial - use company name as primary
        return $client['companyName'] ?? trim(($client['firstName'] ?? '') . ' ' . ($client['lastName'] ?? ''));
    }

    private function formatDate(string $dateString): string
    {
        if (empty($dateString)) {
            return '';
        }

        // Extract just the date part (YYYY-MM-DD) from ISO format
        return substr($dateString, 0, 10);
    }

    private function buildClientMap(array $clients): array
    {
        $map = [];
        foreach ($clients as $client) {
            $map[$client['id']] = $client;
        }
        return $map;
    }

    private function buildMethodMap(array $methods): array
    {
        $map = [];
        foreach ($methods as $method) {
            $map[$method['id']] = $method['name'];
        }
        return $map;
    }

    private function buildOrganizationMap(array $organizations): array
    {
        $map = [];
        foreach ($organizations as $org) {
            $map[$org['id']] = $org['name'];
        }
        return $map;
    }
}
