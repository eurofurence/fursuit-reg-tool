<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use ZipArchive;

class GenerateDirectDSFinVKExport extends Command
{
    protected $signature = 'dsfin:generate-direct-export {--path=storage/app/public/test_dsfin_export.zip}';

    protected $description = 'Generate a test DSFinV-K export with hardcoded sample data for verification tools';

    private string $exportPath;

    public function handle()
    {
        $this->info('Generating test DSFinV-K export with sample data...');

        $targetPath = $this->option('path');
        $fullTargetPath = base_path($targetPath);

        // Ensure directory exists
        $directory = dirname($fullTargetPath);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $this->exportPath = storage_path('app/temp_dsfin_export');
        if (! file_exists($this->exportPath)) {
            mkdir($this->exportPath, 0755, true);
        }

        // Generate all required DSFinV-K CSV files
        $this->generateCashRegister();
        $this->generateCashPointClosing();
        $this->generateVat();
        $this->generateTse();
        $this->generateTransactions();
        $this->generateTransactionsVat();
        $this->generateDataPayment();
        $this->generateLines();
        $this->generateLinesVat();
        $this->generateTransactionsTse();
        $this->generateBusinessCases();
        $this->generatePayment();
        $this->generateCashPerCurrency();
        $this->generateIndexXml();

        // Create ZIP archive
        $this->createZipArchive($fullTargetPath);

        // Clean up temporary directory
        $this->removeDirectory($this->exportPath);

        $this->info('✅ Test DSFinV-K export generated successfully!');
        $this->info("📁 Export location: {$fullTargetPath}");
        $this->info('📊 Export contains 4 sample transactions with full TSE compliance data');
        $this->info('🔍 You can now upload this file to DSFinV-K verification tools');

        $this->newLine();
        $this->info('📋 Sample data summary:');
        $this->info('  • 4 transactions (2 cash, 2 card payments)');
        $this->info('  • Amount range: €10.00 - €25.00');
        $this->info('  • TSE Serial: TSE-TEST-12345678');
        $this->info('  • Transaction numbers: 1001-1004');
        $this->info('  • German 19% VAT calculations');
        $this->info('  • All required DSFinV-K CSV files included');
    }

    private function generateCashRegisterInfo(): void
    {
        // cashregister.csv - Cash register identification
        $content = "Z_KASSE_ID|Z_ERSTELLUNG|Z_NR|KASSE_BRAND|KASSE_MODELL|KASSE_SERIENNR|KASSE_SW_BRAND|KASSE_SW_VERSION|KASSE_BASISWAEH_CODE|KEINE_UST_ZUORDNUNG\n";
        $content .= 'POS1|'.Carbon::now()->format('Y-m-d\TH:i:s')."|1|Eurofurence e.V.|Fursuit Badge Registration|POS-001|Laravel Badge System|1.0.0|EUR|0\n";

        file_put_contents($this->exportPath.'/cashregister.csv', $content);
    }

    private function generateCashRegister(): void
    {
        // cashregister.csv - Cash register identification
        $content = "Z_KASSE_ID|Z_ERSTELLUNG|Z_NR|KASSE_BRAND|KASSE_MODELL|KASSE_SERIENNR|KASSE_SW_BRAND|KASSE_SW_VERSION|KASSE_BASISWAEH_CODE|KEINE_UST_ZUORDNUNG\n";
        $content .= 'POS1|'.Carbon::now()->format('Y-m-d\TH:i:s')."|1|Eurofurence e.V.|Fursuit Badge Registration|POS-001|Laravel Badge System|1.0.0|EUR|0\n";

        file_put_contents($this->exportPath.'/cashregister.csv', $content);
    }

    private function generateCashPointClosing(): void
    {
        // cashpointclosing.csv - Daily closing with company master data
        $content = "Z_KASSE_ID|Z_ERSTELLUNG|Z_NR|Z_BUCHUNGSTAG|TAXONOMIE_VERSION|Z_START_ID|Z_ENDE_ID|NAME|STRASSE|PLZ|ORT|LAND|STNR|USTID|Z_SE_ZAHLUNGEN|Z_SE_BARZAHLUNGEN\n";
        $content .= 'POS1|'.Carbon::now()->format('Y-m-d\TH:i:s').'|1|'.Carbon::now()->format('Y-m-d')."|2.0|1|4|Eurofurence e.V.|Kieler Str. 1|10115|Berlin|DE|27/640/12345|DE123456789|70.00|45.00\n";

        file_put_contents($this->exportPath.'/cashpointclosing.csv', $content);
    }

    private function generateVat(): void
    {
        // vat.csv - VAT rate definitions
        $content = "Z_KASSE_ID|Z_ERSTELLUNG|Z_NR|UST_SCHLUESSEL|UST_SATZ|UST_BESCHR\n";
        $content .= 'POS1|'.Carbon::now()->format('Y-m-d\TH:i:s')."|1|1|19.00|Standard rate\n";
        $content .= 'POS1|'.Carbon::now()->format('Y-m-d\TH:i:s')."|2|2|7.00|Reduced rate\n";
        $content .= 'POS1|'.Carbon::now()->format('Y-m-d\TH:i:s')."|3|5|0.00|No VAT\n";

        file_put_contents($this->exportPath.'/vat.csv', $content);
    }

    private function generateTse(): void
    {
        // tse.csv - TSE device information
        $content = "Z_KASSE_ID|Z_ERSTELLUNG|Z_NR|TSE_ID|TSE_SERIAL|TSE_SIG_ALGO|TSE_ZEITFORMAT|TSE_PD_ENCODING|TSE_PUBLIC_KEY|TSE_ZERTIFIKAT_I|TSE_ZERTIFIKAT_II\n";
        $content .= 'POS1|'.Carbon::now()->format('Y-m-d\TH:i:s').'|1|TSE-TEST-12345678|TSE-TEST-12345678|ecdsa-plain-SHA256|unixTime|UTF-8|'.$this->generateMockPublicKey().'|'.$this->generateMockCertificate()."|\n";

        file_put_contents($this->exportPath.'/tse.csv', $content);
    }

    private function generateTransactions(): void
    {
        // transactions.csv (Bonkopf) - Transaction headers
        $content = "Z_KASSE_ID|Z_ERSTELLUNG|Z_NR|BON_ID|BON_NR|BON_TYP|BON_NAME|TERMINAL_ID|BON_STORNO|BON_START|BON_ENDE|BEDIENER_ID|BEDIENER_NAME|UMS_BRUTTO|KUNDE_NAME|KUNDE_ID|KUNDE_TYP|KUNDE_STRASSE|KUNDE_PLZ|KUNDE_ORT|KUNDE_LAND|KUNDE_USTID|BON_NOTIZ\n";

        $transactions = [
            [
                'bon_id' => 'FSB-2025-1001',
                'bon_nr' => '1001',
                'datetime' => Carbon::now()->subHours(2),
                'amount' => 20.00,
                'payment' => 'cash',
                'tse_number' => '1001',
                'counter' => '501',
            ],
            [
                'bon_id' => 'FSB-2025-1002',
                'bon_nr' => '1002',
                'datetime' => Carbon::now()->subHours(1),
                'amount' => 15.00,
                'payment' => 'card',
                'tse_number' => '1002',
                'counter' => '502',
            ],
            [
                'bon_id' => 'FSB-2025-1003',
                'bon_nr' => '1003',
                'datetime' => Carbon::now()->subMinutes(30),
                'amount' => 25.00,
                'payment' => 'cash',
                'tse_number' => '1003',
                'counter' => '503',
            ],
            [
                'bon_id' => 'FSB-2025-1004',
                'bon_nr' => '1004',
                'datetime' => Carbon::now()->subMinutes(15),
                'amount' => 10.00,
                'payment' => 'card',
                'tse_number' => '1004',
                'counter' => '504',
            ],
        ];

        $nr = 1;
        foreach ($transactions as $tx) {
            $start = $tx['datetime']->format('Y-m-d\TH:i:s');
            $end = $tx['datetime']->addSeconds(5)->format('Y-m-d\TH:i:s');
            $content .= 'POS1|'.Carbon::now()->format('Y-m-d\TH:i:s')."|{$nr}|{$tx['bon_id']}|{$tx['bon_nr']}|Beleg|Kassenbeleg|T1|0|{$start}|{$end}|C001|Max Mustermann|{$tx['amount']}|||||||||\n";
            $nr++;
        }

        file_put_contents($this->exportPath.'/transactions.csv', $content);
    }

    private function generateTransactionsVat(): void
    {
        // transactions_vat.csv (Bonkopf_USt) - VAT per transaction
        $content = "Z_KASSE_ID|Z_ERSTELLUNG|Z_NR|BON_ID|UST_SCHLUESSEL|BON_BRUTTO|BON_NETTO|BON_UST\n";

        $transactions = [
            ['bon_id' => 'FSB-2025-1001', 'brutto' => 20.00],
            ['bon_id' => 'FSB-2025-1002', 'brutto' => 15.00],
            ['bon_id' => 'FSB-2025-1003', 'brutto' => 25.00],
            ['bon_id' => 'FSB-2025-1004', 'brutto' => 10.00],
        ];

        $nr = 1;
        foreach ($transactions as $tx) {
            $netto = round($tx['brutto'] / 1.19, 2);
            $ust = $tx['brutto'] - $netto;
            $content .= 'POS1|'.Carbon::now()->format('Y-m-d\TH:i:s')."|{$nr}|{$tx['bon_id']}|1|{$tx['brutto']}|{$netto}|{$ust}\n";
            $nr++;
        }

        file_put_contents($this->exportPath.'/transactions_vat.csv', $content);
    }

    private function generateDataPayment(): void
    {
        // datapayment.csv (Bonkopf_Zahlarten) - Payment methods per transaction
        $content = "Z_KASSE_ID|Z_ERSTELLUNG|Z_NR|BON_ID|ZAHLART_TYP|ZAHLART_NAME|ZAHLWAEH_CODE|ZAHLWAEH_BETRAG|BASISWAEH_BETRAG\n";

        $payments = [
            ['bon_id' => 'FSB-2025-1001', 'type' => 'Bar', 'name' => 'Bargeld', 'amount' => 20.00],
            ['bon_id' => 'FSB-2025-1002', 'type' => 'Kreditkarte', 'name' => 'SumUp', 'amount' => 15.00],
            ['bon_id' => 'FSB-2025-1003', 'type' => 'Bar', 'name' => 'Bargeld', 'amount' => 25.00],
            ['bon_id' => 'FSB-2025-1004', 'type' => 'Kreditkarte', 'name' => 'SumUp', 'amount' => 10.00],
        ];

        $nr = 1;
        foreach ($payments as $payment) {
            $content .= 'POS1|'.Carbon::now()->format('Y-m-d\TH:i:s')."|{$nr}|{$payment['bon_id']}|{$payment['type']}|{$payment['name']}|EUR|{$payment['amount']}|{$payment['amount']}\n";
            $nr++;
        }

        file_put_contents($this->exportPath.'/datapayment.csv', $content);
    }

    private function generateLines(): void
    {
        // lines.csv (Bonpos) - Transaction line items
        $content = "Z_KASSE_ID|Z_ERSTELLUNG|Z_NR|BON_ID|POS_ZEILE|GUTSCHEIN_NR|ARTIKELTEXT|POS_TERMINAL_ID|GV_TYP|GV_NAME|INHAUS|P_STORNO|AGENTUR_ID|ART_NR|GTIN|WARENGR_ID|WARENGR|MENGE|FAKTOR|EINHEIT|STK_BR\n";

        $lines = [
            ['bon_id' => 'FSB-2025-1001', 'line' => 1, 'text' => 'Fursuit Badge Registration - Premium', 'art_nr' => 'BADGE-PREM-001', 'amount' => 20.00],
            ['bon_id' => 'FSB-2025-1002', 'line' => 1, 'text' => 'Fursuit Badge Registration - Standard', 'art_nr' => 'BADGE-STD-002', 'amount' => 15.00],
            ['bon_id' => 'FSB-2025-1003', 'line' => 1, 'text' => 'Fursuit Badge Registration - Premium', 'art_nr' => 'BADGE-PREM-003', 'amount' => 20.00],
            ['bon_id' => 'FSB-2025-1003', 'line' => 2, 'text' => 'Express Service Fee', 'art_nr' => 'SERVICE-EXP-001', 'amount' => 5.00],
            ['bon_id' => 'FSB-2025-1004', 'line' => 1, 'text' => 'Replacement Badge', 'art_nr' => 'BADGE-REPL-001', 'amount' => 10.00],
        ];

        $nr = 1;
        foreach ($lines as $line) {
            $content .= 'POS1|'.Carbon::now()->format('Y-m-d\TH:i:s')."|{$nr}|{$line['bon_id']}|{$line['line']}||{$line['text']}|T1|Umsatz|Warenverkauf|1|0|0|{$line['art_nr']}||001|Badges|1.00|1.00|Stk|{$line['amount']}\n";
            $nr++;
        }

        file_put_contents($this->exportPath.'/lines.csv', $content);
    }

    private function generateLinesVat(): void
    {
        // lines_vat.csv (Bonpos_USt) - VAT per line item
        $content = "Z_KASSE_ID|Z_ERSTELLUNG|Z_NR|BON_ID|POS_ZEILE|UST_SCHLUESSEL|POS_BRUTTO|POS_NETTO|POS_UST\n";

        $lines = [
            ['bon_id' => 'FSB-2025-1001', 'line' => 1, 'brutto' => 20.00],
            ['bon_id' => 'FSB-2025-1002', 'line' => 1, 'brutto' => 15.00],
            ['bon_id' => 'FSB-2025-1003', 'line' => 1, 'brutto' => 20.00],
            ['bon_id' => 'FSB-2025-1003', 'line' => 2, 'brutto' => 5.00],
            ['bon_id' => 'FSB-2025-1004', 'line' => 1, 'brutto' => 10.00],
        ];

        $nr = 1;
        foreach ($lines as $line) {
            $netto = round($line['brutto'] / 1.19, 2);
            $ust = $line['brutto'] - $netto;
            $content .= 'POS1|'.Carbon::now()->format('Y-m-d\TH:i:s')."|{$nr}|{$line['bon_id']}|{$line['line']}|1|{$line['brutto']}|{$netto}|{$ust}\n";
            $nr++;
        }

        file_put_contents($this->exportPath.'/lines_vat.csv', $content);
    }

    private function generateTransactionsTse(): void
    {
        // transactions_tse.csv (TSE_Transaktionen) - TSE data per transaction
        $content = "Z_KASSE_ID|Z_ERSTELLUNG|Z_NR|BON_ID|TSE_ID|TSE_TANR|TSE_TA_START|TSE_TA_ENDE|TSE_TA_VORGANGSART|TSE_TA_SIGZ|TSE_TA_SIG|TSE_TA_FEHLER|TSE_VORGANGSDATEN\n";

        $transactions = [
            ['bon_id' => 'FSB-2025-1001', 'tanr' => '1001', 'counter' => '501', 'datetime' => Carbon::now()->subHours(2)],
            ['bon_id' => 'FSB-2025-1002', 'tanr' => '1002', 'counter' => '502', 'datetime' => Carbon::now()->subHours(1)],
            ['bon_id' => 'FSB-2025-1003', 'tanr' => '1003', 'counter' => '503', 'datetime' => Carbon::now()->subMinutes(30)],
            ['bon_id' => 'FSB-2025-1004', 'tanr' => '1004', 'counter' => '504', 'datetime' => Carbon::now()->subMinutes(15)],
        ];

        $nr = 1;
        foreach ($transactions as $tx) {
            $signature = $this->generateMockSignature();
            $start = $tx['datetime']->format('Y-m-d\TH:i:s');
            $ende = $tx['datetime']->addSeconds(5)->format('Y-m-d\TH:i:s');
            $vorgangsdaten = base64_encode(json_encode(['receipt_id' => $tx['bon_id'], 'type' => 'Kassenbeleg']));
            $content .= 'POS1|'.Carbon::now()->format('Y-m-d\TH:i:s')."|{$nr}|{$tx['bon_id']}|TSE-TEST-12345678|{$tx['tanr']}|{$start}|{$ende}|Kassenbeleg-V1|{$tx['counter']}|{$signature}||{$vorgangsdaten}\n";
            $nr++;
        }

        file_put_contents($this->exportPath.'/transactions_tse.csv', $content);
    }

    private function createZipArchive(string $zipPath): void
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            // Add all CSV files
            $csvFiles = glob($this->exportPath.'/*.csv');
            foreach ($csvFiles as $file) {
                $zip->addFile($file, basename($file));
            }

            // Add index.xml
            $xmlFile = $this->exportPath.'/index.xml';
            if (file_exists($xmlFile)) {
                $zip->addFile($xmlFile, basename($xmlFile));
            }

            $zip->close();
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != '.' && $object != '..') {
                    if (is_dir($dir.'/'.$object) && ! is_link($dir.'/'.$object)) {
                        $this->removeDirectory($dir.'/'.$object);
                    } else {
                        unlink($dir.'/'.$object);
                    }
                }
            }
            rmdir($dir);
        }
    }

    private function generateBusinessCases(): void
    {
        // businesscases.csv (Z_GV_Typ) - Daily summaries by business case type
        $content = "Z_KASSE_ID|Z_ERSTELLUNG|Z_NR|GV_TYP|GV_NAME|AGENTUR_ID|UST_SCHLUESSEL|Z_UMS_BRUTTO|Z_UMS_NETTO|Z_UST\n";

        // Summary for all transactions (only "Umsatz" type)
        $totalBrutto = 70.00; // Sum of all transactions
        $totalNetto = round($totalBrutto / 1.19, 2);
        $totalUst = $totalBrutto - $totalNetto;

        $content .= 'POS1|'.Carbon::now()->format('Y-m-d\TH:i:s')."|1|Umsatz|Warenverkauf|0|1|{$totalBrutto}|{$totalNetto}|{$totalUst}\n";

        file_put_contents($this->exportPath.'/businesscases.csv', $content);
    }

    private function generatePayment(): void
    {
        // payment.csv (Z_Zahlart) - Daily payment summaries
        $content = "Z_KASSE_ID|Z_ERSTELLUNG|Z_NR|ZAHLART_TYP|ZAHLART_NAME|Z_ZAHLART_BETRAG\n";

        // Summarize payments: 45€ cash, 25€ card
        $content .= 'POS1|'.Carbon::now()->format('Y-m-d\TH:i:s')."|1|Bar|Bargeld|45.00\n";
        $content .= 'POS1|'.Carbon::now()->format('Y-m-d\TH:i:s')."|2|Kreditkarte|SumUp|25.00\n";

        file_put_contents($this->exportPath.'/payment.csv', $content);
    }

    private function generateCashPerCurrency(): void
    {
        // cash_per_currency.csv (Z_Waehrungen) - Cash balance per currency
        $content = "Z_KASSE_ID|Z_ERSTELLUNG|Z_NR|ZAHLART_WAEH|ZAHLART_BETRAG_WAEH\n";

        // Cash balance in EUR
        $content .= 'POS1|'.Carbon::now()->format('Y-m-d\TH:i:s')."|1|EUR|45.00\n";

        file_put_contents($this->exportPath.'/cash_per_currency.csv', $content);
    }

    private function generateIndexXml(): void
    {
        // index.xml - Descriptor file for DSFinV-K export
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<DataSet>'."\n";
        $xml .= '  <DataSupplier>'."\n";
        $xml .= '    <Name>Eurofurence e.V.</Name>'."\n";
        $xml .= '    <Location>Berlin</Location>'."\n";
        $xml .= '    <Comment>Fursuit Badge Registration System</Comment>'."\n";
        $xml .= '  </DataSupplier>'."\n";
        $xml .= '  <CreatingSystem>'."\n";
        $xml .= '    <Name>Laravel Badge System</Name>'."\n";
        $xml .= '    <Version>1.0.0</Version>'."\n";
        $xml .= '  </CreatingSystem>'."\n";
        $xml .= '  <CreatedAt>'.Carbon::now()->format('Y-m-d\TH:i:s').'</CreatedAt>'."\n";
        $xml .= '  <TaxonomyVersion>2.0</TaxonomyVersion>'."\n";
        $xml .= '  <DataVersion>1.0</DataVersion>'."\n";
        $xml .= '  <Files>'."\n";
        $xml .= '    <File name="cashregister.csv" />'."\n";
        $xml .= '    <File name="cashpointclosing.csv" />'."\n";
        $xml .= '    <File name="vat.csv" />'."\n";
        $xml .= '    <File name="tse.csv" />'."\n";
        $xml .= '    <File name="transactions.csv" />'."\n";
        $xml .= '    <File name="transactions_vat.csv" />'."\n";
        $xml .= '    <File name="datapayment.csv" />'."\n";
        $xml .= '    <File name="lines.csv" />'."\n";
        $xml .= '    <File name="lines_vat.csv" />'."\n";
        $xml .= '    <File name="transactions_tse.csv" />'."\n";
        $xml .= '    <File name="businesscases.csv" />'."\n";
        $xml .= '    <File name="payment.csv" />'."\n";
        $xml .= '    <File name="cash_per_currency.csv" />'."\n";
        $xml .= '  </Files>'."\n";
        $xml .= '</DataSet>'."\n";

        file_put_contents($this->exportPath.'/index.xml', $xml);
    }

    private function generateMockSignature(): string
    {
        // Generate a realistic-looking TSE signature for testing
        return 'MEQCIDx7Kj9QmF+8VwK'.bin2hex(random_bytes(20)).'AIgB2mN5K+pqR7XvL'.bin2hex(random_bytes(15));
    }

    private function generateMockPublicKey(): string
    {
        // Generate a realistic-looking public key for testing
        return base64_encode(random_bytes(65));
    }

    private function generateMockCertificate(): string
    {
        // Generate a realistic-looking certificate for testing
        return base64_encode(random_bytes(256));
    }
}
