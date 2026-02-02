<?php

declare(strict_types=1);

namespace App\Service;

use League\Csv\Writer;
use SplTempFileObject;

class CsvGenerator
{
    private array $clientMap;
    private array $methodMap;

    public function __construct(array $clients, array $methods)
    {
        $this->clientMap = $this->buildClientMap($clients);
        $this->methodMap = $this->buildMethodMap($methods);
    }

    public function generate(string $filename, array $payments): void
    {
        $csv = Writer::createFromFileObject(new SplTempFileObject());

        $csv->insertOne($this->getHeaderLine());

        foreach ($payments as $payment) {
            // If payment has invoice covers, create one row per invoice
            if (!empty($payment['invoiceDetails'])) {
                foreach ($payment['invoiceDetails'] as $invoiceDetail) {
                    $csv->insertOne($this->getPaymentLine($payment, $invoiceDetail));
                }
            } else {
                // Payment with no linked invoices (credit only)
                $csv->insertOne($this->getPaymentLine($payment, null));
            }
        }

        $csv->download($filename);
    }

    private function getHeaderLine(): array
    {
        return [
            'Payment ID',
            'Payment Date',
            'Client ID',
            'Client Name',
            'Company Name',
            'Payment Method',
            'Currency',
            'Total Payment Amount',
            'Credit Amount',
            'Invoice ID',
            'Invoice Number',
            'Invoice Date',
            'Invoice Total',
            'Invoice Status',
            'Amount Applied to Invoice',
            'Note',
        ];
    }

    private function getPaymentLine(array $payment, ?array $invoiceDetail): array
    {
        $client = $this->clientMap[$payment['clientId']] ?? [];
        $clientName = $this->formatClientName($client);
        $companyName = $client['companyName'] ?? '';
        $methodName = $this->methodMap[$payment['methodId'] ?? ''] ?? 'Unknown';

        return [
            $payment['id'],
            $this->formatDate($payment['createdDate'] ?? ''),
            $payment['clientId'] ?? '',
            $clientName,
            $companyName,
            $methodName,
            $payment['currencyCode'] ?? '',
            $payment['amount'] ?? 0,
            $payment['creditAmount'] ?? 0,
            $invoiceDetail['invoiceId'] ?? '',
            $invoiceDetail['invoiceNumber'] ?? '',
            $invoiceDetail['invoiceDate'] ?? '',
            $invoiceDetail['invoiceTotal'] ?? '',
            $invoiceDetail['invoiceStatus'] ?? '',
            $invoiceDetail['amountCovered'] ?? '',
            $payment['note'] ?? '',
        ];
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

    private function formatInvoiceStatus(int $status): string
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
}
