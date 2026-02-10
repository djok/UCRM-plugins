<?php

declare(strict_types=1);

namespace App\Service;

use League\Csv\Writer;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use SplTempFileObject;

/**
 * Generates sales report in Plus-Minus compatible CSV format.
 *
 * Format specification: 21 mandatory columns, semicolon delimiter, Windows-1251 encoding.
 * Each invoice item is a separate row. Document-level fields only on first row.
 */
class SalesReportGenerator
{
    // Payment type codes for Plus-Minus
    private const PAYMENT_CASH = 1;
    private const PAYMENT_BANK = 2;
    private const PAYMENT_CARD = 4;

    // Document type codes
    private const DOC_TYPE_INVOICE = 1;
    private const DOC_TYPE_CREDIT_NOTE = 3;

    // UCRM Cash payment method UUID
    private const CASH_METHOD_ID = '6efe0fa8-36b2-4dd1-b049-427bffc7d369';

    private array $clientMap;
    private array $paymentCache;
    private array $serviceLabelMap;
    private array $surchargeLabelMap;
    private $api;

    /**
     * @param array $clients All clients from UCRM
     * @param mixed $api UCRM API instance
     * @param array $serviceLabelMap Map of clientServiceId => accounting label
     * @param array $surchargeLabelMap Map of surchargeId => accounting label
     */
    public function __construct(array $clients, $api, array $serviceLabelMap = [], array $surchargeLabelMap = [])
    {
        $this->clientMap = $this->buildClientMap($clients);
        $this->paymentCache = [];
        $this->serviceLabelMap = $serviceLabelMap;
        $this->surchargeLabelMap = $surchargeLabelMap;
        $this->api = $api;
    }

    /**
     * Generate CSV file to disk with Windows-1251 encoding and semicolon delimiter.
     * No header row - Plus-Minus format requirement.
     */
    public function generateCsvToFile(string $filepath, array $documents): void
    {
        $csv = Writer::createFromFileObject(new SplTempFileObject());
        $csv->setDelimiter(';');

        // NO header row - Plus-Minus specification requirement

        foreach ($documents as $document) {
            $rows = $this->getDocumentRows($document);
            foreach ($rows as $row) {
                $csv->insertOne($row);
            }
        }

        // Convert to Windows-1251 with CRLF line endings, strip CSV enclosure quotes
        $content = $csv->toString();
        $content = str_replace('"', '', $content);
        $content = str_replace("\n", "\r\n", $content);
        $convertedContent = iconv('UTF-8', 'WINDOWS-1251//TRANSLIT', $content);
        file_put_contents($filepath, $convertedContent);
    }

    /**
     * Generate XLSX file to disk (with header for human readability).
     */
    public function generateXlsxToFile(string $filepath, array $documents): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Sales');

        // Set headers (XLSX keeps headers for readability)
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
        foreach ($documents as $document) {
            $rows = $this->getDocumentRows($document);
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

    /**
     * Get header line for XLSX (CSV has no header per specification).
     */
    private function getHeaderLine(): array
    {
        return [
            'Вид. Документ',        // 1
            'Дата',                  // 2
            'Номер',                 // 3
            'Партньор',              // 4
            'ЕИК',                   // 5
            'ИН по ДДС',             // 6
            'Склад',                 // 7
            'Вид',                   // 8
            'Група',                 // 9
            'Подгрупа',              // 10
            'Наименование',          // 11
            'Код',                   // 12
            'Мярка',                 // 13
            'Количество',            // 14
            'Ед. Цена',              // 15
            'Стойност',              // 16
            'ДДС Вид',               // 17
            'Вид плащане',           // 18
            'Обща стойност с ДДС',   // 19
            'ДДС (документ)',        // 20
            'Платено',               // 21
        ];
    }

    /**
     * Generate rows for a single document (one row per item).
     * Document-level fields only on first row.
     */
    private function getDocumentRows(array $document): array
    {
        $rows = [];
        $items = $document['items'] ?? [];

        // Document-level data
        $docType = $document['_docType'] ?? self::DOC_TYPE_INVOICE;
        $docDate = $this->formatDate($document['createdDate'] ?? '');
        $docNumber = $document['number'] ?? '';
        $partnerName = $this->sanitizeString($this->formatClientName($document));
        $eik = $document['clientCompanyRegistrationNumber'] ?? '';
        $vatId = $document['clientCompanyTaxId'] ?? '';
        $paymentType = $this->determinePaymentType($document);
        $totalWithVat = $this->formatNumber((float)($document['total'] ?? 0));
        $totalVat = $this->formatNumber((float)($document['totalTaxAmount'] ?? 0));
        $paidAmount = $this->calculatePaidAmount($document);

        // If no items, create a single row with document data only
        if (empty($items)) {
            $rows[] = [
                $docType,           // 1. Вид. Документ
                $docDate,           // 2. Дата
                $docNumber,         // 3. Номер
                $partnerName,       // 4. Партньор
                $eik,               // 5. ЕИК
                $vatId,             // 6. ИН по ДДС
                '',                 // 7. Склад
                'УСЛУГИ',           // 8. Вид
                '',                 // 9. Група
                '',                 // 10. Подгрупа
                'Услуга',           // 11. Наименование
                '',                 // 12. Код (empty for services)
                '',                 // 13. Мярка (empty for services)
                '',                 // 14. Количество (empty for services)
                '',                 // 15. Ед. Цена (empty for services)
                $this->formatNumber($this->calculateSubtotal($document)), // 16. Стойност
                'ДДС20',            // 17. ДДС Вид
                $paymentType,       // 18. Вид плащане
                $totalWithVat,      // 19. Обща стойност с ДДС
                $totalVat,          // 20. ДДС (документ)
                $paidAmount,        // 21. Платено
            ];
            return $rows;
        }

        // Calculate discount ratio for proportional distribution
        $subtotal = (float)($document['subtotal'] ?? 0);
        $totalUntaxed = (float)($document['totalUntaxed'] ?? $subtotal);
        $discountRatio = ($subtotal > 0) ? ($totalUntaxed / $subtotal) : 1.0;

        // Create one row per item
        $isFirstRow = true;
        $itemIndex = 0;
        $itemCount = count($items);
        $runningTotal = 0.0;
        foreach ($items as $item) {
            $itemIndex++;
            $itemType = $this->getItemType($item['type'] ?? 'service');
            $itemLabel = $this->sanitizeString($item['label'] ?? '');
            $isService = ($itemType === 'УСЛУГИ');
            $itemCode = $isService ? '' : (string)($item['serviceId'] ?? $item['id'] ?? '');
            $rawTotal = (float)($item['total'] ?? 0);

            // Apply proportional discount to line total
            if ($itemIndex === $itemCount) {
                // Last item gets the remainder to avoid rounding errors
                $lineTotal = round($totalUntaxed - $runningTotal, 2);
            } else {
                $lineTotal = round($rawTotal * $discountRatio, 2);
                $runningTotal += $lineTotal;
            }

            // Calculate discounted unit price
            $rawQuantity = (float)($item['quantity'] ?? 1);
            $discountedUnitPrice = ($rawQuantity != 0) ? ($lineTotal / $rawQuantity) : 0.0;
            $quantity = $isService ? '' : $rawQuantity;
            $unitPrice = $isService ? '' : $this->formatNumber($discountedUnitPrice);

            // Override label from service mapping config
            $mappedLabel = $this->getAccountingLabel($item);
            if ($mappedLabel !== null) {
                $itemLabel = $mappedLabel;
            }

            $row = [
                $docType,           // 1. Вид. Документ
                $docDate,           // 2. Дата
                $docNumber,         // 3. Номер
                $partnerName,       // 4. Партньор
                $eik,               // 5. ЕИК
                $vatId,             // 6. ИН по ДДС
                '',                 // 7. Склад
                $itemType,          // 8. Вид
                '',                 // 9. Група
                '',                 // 10. Подгрупа
                $itemLabel,         // 11. Наименование
                $itemCode,          // 12. Код (empty for services)
                $isService ? '' : 'бр.',  // 13. Мярка (empty for services)
                $quantity,          // 14. Количество (empty for services)
                $unitPrice,         // 15. Ед. Цена (empty for services)
                $this->formatNumber($lineTotal),   // 16. Стойност
                'ДДС20',            // 17. ДДС Вид
                $paymentType,       // 18. Вид плащане
                $isFirstRow ? $totalWithVat : '', // 19. Обща стойност с ДДС (first row only)
                $isFirstRow ? $totalVat : '',     // 20. ДДС (документ) (first row only)
                $isFirstRow ? $paidAmount : '',   // 21. Платено (first row only)
            ];

            $rows[] = $row;
            $isFirstRow = false;
        }

        return $rows;
    }

    /**
     * Determine item type: 'product' -> СТОКИ, else -> УСЛУГИ
     */
    private function getItemType(string $type): string
    {
        return $type === 'product' ? 'СТОКИ' : 'УСЛУГИ';
    }

    /**
     * Determine payment type code based on payment method.
     */
    private function determinePaymentType(array $document): int
    {
        $paymentCovers = $document['paymentCovers'] ?? [];

        if (empty($paymentCovers)) {
            return self::PAYMENT_BANK; // Default to bank transfer
        }

        $firstCover = $paymentCovers[0];
        $paymentId = $firstCover['paymentId'] ?? null;

        if ($paymentId === null) {
            return self::PAYMENT_BANK;
        }

        $payment = $this->getPayment($paymentId);

        if ($payment === null) {
            return self::PAYMENT_BANK;
        }

        $methodId = $payment['methodId'] ?? '';

        if ($methodId === self::CASH_METHOD_ID) {
            return self::PAYMENT_CASH;
        }

        // TODO: Add card method ID mapping if needed
        return self::PAYMENT_BANK;
    }

    /**
     * Calculate paid amount from payment covers.
     */
    private function calculatePaidAmount(array $document): string
    {
        $paymentCovers = $document['paymentCovers'] ?? [];
        $paidAmount = 0.0;

        foreach ($paymentCovers as $cover) {
            $paidAmount += (float)($cover['amount'] ?? 0);
        }

        return $this->formatNumber($paidAmount);
    }

    /**
     * Calculate subtotal (total without tax) for document without items.
     */
    private function calculateSubtotal(array $document): float
    {
        $total = (float)($document['total'] ?? 0);
        $tax = (float)($document['totalTaxAmount'] ?? 0);

        return $total - $tax;
    }

    /**
     * Sanitize string by removing prohibited characters: " ' / \ &
     */
    private function sanitizeString(string $value): string
    {
        return str_replace(['"', "'", '/', '\\', '&'], '', $value);
    }

    /**
     * Format date as dd.mm.yyyy with leading zeros.
     */
    private function formatDate(string $dateString): string
    {
        if (empty($dateString)) {
            return '';
        }

        // Extract date part (YYYY-MM-DD) and convert to DD.MM.YYYY with leading zeros
        $datePart = substr($dateString, 0, 10);
        $parts = explode('-', $datePart);

        if (count($parts) !== 3) {
            return $dateString;
        }

        // Format with leading zeros: %02d ensures 2 digits
        return sprintf('%02d.%02d.%s', (int)$parts[2], (int)$parts[1], $parts[0]);
    }

    /**
     * Format number with decimal point and 2 decimal places.
     */
    private function formatNumber(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function formatClientName(array $document): string
    {
        if (!empty($document['clientCompanyName'])) {
            return $document['clientCompanyName'];
        }

        $firstName = $document['clientFirstName'] ?? '';
        $lastName = $document['clientLastName'] ?? '';

        return trim($firstName . ' ' . $lastName);
    }

    private function getPayment(int $paymentId): ?array
    {
        if (isset($this->paymentCache[$paymentId])) {
            return $this->paymentCache[$paymentId];
        }

        try {
            $payment = $this->api->get('payments/' . $paymentId);
            $this->paymentCache[$paymentId] = $payment;
            return $payment;
        } catch (\Exception $e) {
            $this->paymentCache[$paymentId] = null;
            return null;
        }
    }

    /**
     * Get the configured accounting label for an invoice item, or null if none.
     * Checks both service plan mapping (via serviceId) and surcharge mapping (via serviceSurchargeId).
     */
    private function getAccountingLabel(array $item): ?string
    {
        // Check surcharge mapping first
        $surchargeId = $item['serviceSurchargeId'] ?? null;
        if ($surchargeId !== null && isset($this->surchargeLabelMap[$surchargeId])) {
            return $this->surchargeLabelMap[$surchargeId];
        }

        // Then check service plan mapping
        $serviceId = $item['serviceId'] ?? null;
        if ($serviceId === null) {
            return null;
        }

        return $this->serviceLabelMap[$serviceId] ?? null;
    }

    private function buildClientMap(array $clients): array
    {
        $map = [];
        foreach ($clients as $client) {
            $map[$client['id']] = $client;
        }
        return $map;
    }
}
