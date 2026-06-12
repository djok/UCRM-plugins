<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Statement;

/**
 * Parses a Revolut Business account-statement CSV export. Unlike the API, the
 * statement carries the sender's IBAN ("Sender account") and name for every
 * incoming transfer, so it is the authoritative source for client matching.
 * Only COMPLETED incoming (positive) TOPUP/TRANSFER rows are returned.
 */
final class StatementCsvParser
{
    private const REQUIRED_COLUMNS = ['ID', 'Type', 'State', 'Amount'];
    private const INCOMING_TYPES = ['TOPUP', 'TRANSFER'];

    /**
     * @return list<array{id:string,date:string,amount:float,currency:string,reference:string,senderName:string,senderIban:string}>
     */
    public function parse(string $csvContent): array
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            return [];
        }
        // Strip a UTF-8 BOM so the first header cell matches.
        fwrite($stream, (string) preg_replace('/^\xEF\xBB\xBF/', '', $csvContent));
        rewind($stream);

        $header = fgetcsv($stream);
        if (! is_array($header)) {
            fclose($stream);

            return [];
        }
        $index = array_flip(array_map('strval', $header));
        foreach (self::REQUIRED_COLUMNS as $column) {
            if (! isset($index[$column])) {
                fclose($stream);

                return [];
            }
        }

        $cell = static function (array $row, string $column) use ($index): string {
            $i = $index[$column] ?? null;

            return $i !== null && isset($row[$i]) ? trim((string) $row[$i]) : '';
        };

        $rows = [];
        while (($row = fgetcsv($stream)) !== false) {
            if (! is_array($row) || count($row) < 2) {
                continue;
            }
            $type = strtoupper($cell($row, 'Type'));
            $state = strtoupper($cell($row, 'State'));
            $amount = (float) $cell($row, 'Amount');
            $id = $cell($row, 'ID');
            if ($id === '' || ! in_array($type, self::INCOMING_TYPES, true) || $state !== 'COMPLETED' || $amount <= 0.0) {
                continue;
            }

            $senderName = $cell($row, 'Sender name');
            if ($senderName === '') {
                $senderName = (string) preg_replace(
                    '/^(payment from|добавени пари от)\s+/iu',
                    '',
                    $cell($row, 'Description'),
                );
            }

            $rows[] = [
                'id' => $id,
                'date' => $cell($row, 'Date completed (UTC)') ?: $cell($row, 'Date started (UTC)'),
                'amount' => $amount,
                'currency' => $cell($row, 'Payment currency'),
                'reference' => $cell($row, 'Reference'),
                'senderName' => $senderName,
                'senderIban' => $cell($row, 'Sender account'),
            ];
        }
        fclose($stream);

        return $rows;
    }
}
