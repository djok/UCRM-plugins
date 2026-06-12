<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Tests\Statement;

use PHPUnit\Framework\TestCase;
use RevolutPaymentsImport\Statement\StatementCsvParser;

final class StatementCsvParserTest extends TestCase
{
    private function sampleCsv(): string
    {
        $header = 'Date started (UTC),Date completed (UTC),Date started (Europe/Sofia),Date completed (Europe/Sofia),ID,Type,State,Description,Reference,Payer,Card number,Card label,Card state,Orig currency,Orig amount,Payment currency,Amount,Total amount,Exchange rate,Fee,Fee currency,Balance,Account,International account number,Beneficiary account number,Beneficiary sort code or routing number,Beneficiary IBAN,Beneficiary BIC,Beneficiary name,MCC,Related transaction id,Spend program,Sender account,Sender name,Card references';

        return "\xEF\xBB\xBF" . $header . "\n"
            // incoming client payment — must be imported
            . '2026-05-29,2026-05-29,2026-05-29,2026-05-29,aaaa1111-0001-e3b3-0b40-03e1b2ee7439,TOPUP,COMPLETED,Добавени пари от ACME LTD,"F-RA 1001 / 27.05.26",,,,,EUR,8.86,EUR,8.86,8.86,,0.00,EUR,957.56,EUR Public Invoices,LT000000000000000000,,,,,,,,,BG00TEST80001000000001,ACME LTD,' . "\n"
            // outgoing card payment — skipped (negative, CARD_PAYMENT)
            . '2026-05-30,2026-05-31,2026-05-30,2026-05-31,aaaa1111-0002-e3b1-0b40-03415132d598,CARD_PAYMENT,COMPLETED,Fuel Station,,Rosen,4633,Virtual,ACTIVE,EUR,52.66,EUR,-52.66,-52.66,,0.00,EUR,698.98,EUR Public Invoices,LT000000000000000000,,,,,,5541,,,,,' . "\n"
            // outgoing internal transfer — skipped (negative)
            . '2026-05-18,2026-05-18,2026-05-18,2026-05-18,aaaa1111-0003-e3c7-0b40-03338da6544e,TRANSFER,COMPLETED,До Main,,Rosen,,,,EUR,966.23,EUR,-966.23,-966.23,,0.00,EUR,30.00,EUR Public Invoices,LT000000000000000000,,,,,,,,,,,' . "\n"
            // pending topup — skipped (not COMPLETED)
            . '2026-05-29,2026-05-29,2026-05-29,2026-05-29,aaaa1111-0004-e380-0b40-03b0cdc58aeb,TOPUP,PENDING,Добавени пари от OTHER LTD,REF-X,,,,,EUR,12.78,EUR,12.78,12.78,,0.00,EUR,948.70,EUR Public Invoices,LT000000000000000000,,,,,,,,,BG00TEST92401000000002,OTHER LTD,' . "\n";
    }

    public function testParsesOnlyCompletedIncomingRows(): void
    {
        $rows = (new StatementCsvParser())->parse($this->sampleCsv());

        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame('aaaa1111-0001-e3b3-0b40-03e1b2ee7439', $row['id']);
        self::assertSame(8.86, $row['amount']);
        self::assertSame('EUR', $row['currency']);
        self::assertSame('F-RA 1001 / 27.05.26', $row['reference']);
        self::assertSame('ACME LTD', $row['senderName']);
        self::assertSame('BG00TEST80001000000001', $row['senderIban']);
        self::assertSame('2026-05-29', $row['date']);
    }

    public function testReturnsEmptyForMissingHeader(): void
    {
        self::assertSame([], (new StatementCsvParser())->parse("no,real,header\n1,2,3\n"));
        self::assertSame([], (new StatementCsvParser())->parse(''));
    }
}
