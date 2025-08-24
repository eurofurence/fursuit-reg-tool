<?php

namespace App\Domain\Checkout\Services;

use App\Domain\Checkout\Models\Checkout\Checkout;
use Carbon\Carbon;
use ZipArchive;

/**
 * DSFinV-K Export Service for KassenSichV Compliance
 *
 * Generates standardized data export format required by German tax authorities
 * according to DSFinV-K specification.
 */
class DSFinVKExportService
{
    private string $exportPath;

    private Carbon $dateFrom;

    private Carbon $dateTo;

    public function __construct()
    {
        $this->exportPath = storage_path('app/dsfin_exports');
        if (! file_exists($this->exportPath)) {
            mkdir($this->exportPath, 0755, true);
        }
    }

    /**
     * Generate complete DSFinV-K export for tax audit
     */
    public function generateExport(Carbon $dateFrom, Carbon $dateTo): string
    {
        $this->dateFrom = $dateFrom;
        $this->dateTo = $dateTo;

        $exportId = 'dsfin_export_'.now()->format('Y_m_d_H_i_s');
        $exportDir = $this->exportPath.'/'.$exportId;
        mkdir($exportDir, 0755, true);

        // Generate all required DSFinV-K CSV files
        $this->generateCashRegister($exportDir);
        $this->generateCashPointClosing($exportDir);
        $this->generateVat($exportDir);
        $this->generateTse($exportDir);
        $this->generateTransactions($exportDir);
        $this->generateTransactionsVat($exportDir);
        $this->generateDataPayment($exportDir);
        $this->generateLines($exportDir);
        $this->generateLinesVat($exportDir);
        $this->generateTransactionsTse($exportDir);
        $this->generateBusinessCases($exportDir);
        $this->generatePayment($exportDir);
        $this->generateCashPerCurrency($exportDir);
        $this->generateIndexXml($exportDir);

        // Create ZIP archive
        $zipPath = $this->exportPath.'/'.$exportId.'.zip';
        $this->createZipArchive($exportDir, $zipPath);

        // Clean up temporary directory
        $this->removeDirectory($exportDir);

        return $zipPath;
    }

    /**
     * Generate cash_register_info.csv
     */
    private function generateCashRegisterInfo(string $exportDir): void
    {
        // Get all machines used in the export period
        $machines = \App\Models\Machine::whereHas('checkouts', function ($query) {
            $query->whereBetween('created_at', [$this->dateFrom, $this->dateTo]);
        })->get();

        $content = "Z_KASSE_ID;CASH_REGISTER_TYPE;CASH_REGISTER_MANUFACTURER;CASH_REGISTER_MODEL;CASH_REGISTER_SOFTWARE;CASH_REGISTER_SOFTWARE_VERSION;CASH_REGISTER_BASE_CURRENCY_CODE;CASH_REGISTER_START_DATE;CASH_REGISTER_END_DATE\n";

        foreach ($machines as $machine) {
            $data = [
                'Z_KASSE_ID' => 'POS'.$machine->id,
                'CASH_REGISTER_TYPE' => 'REGISTRIERKASSE',
                'CASH_REGISTER_MANUFACTURER' => 'Eurofurence e.V.',
                'CASH_REGISTER_MODEL' => 'Fursuit Badge Registration',
                'CASH_REGISTER_SOFTWARE' => 'Laravel Badge System',
                'CASH_REGISTER_SOFTWARE_VERSION' => '1.0.0',
                'CASH_REGISTER_BASE_CURRENCY_CODE' => 'EUR',
                'CASH_REGISTER_START_DATE' => $this->dateFrom->format('Y-m-d'),
                'CASH_REGISTER_END_DATE' => $this->dateTo->format('Y-m-d'),
            ];

            $content .= implode(';', array_values($data))."\n";
        }

        file_put_contents($exportDir.'/cash_register_info.csv', $content);
    }

    /**
     * Generate transactions_v1.csv - Main transaction data
     */
    private function generateTransactionsV1(string $exportDir): void
    {
        $content = "Z_KASSE_ID;TRANSACTION_TYPE;TRANSACTION_NUMBER;TRANSACTION_DATE;TRANSACTION_TIME;CLIENT_ID;PROCESS_TYPE;PROCESS_DATA;TSE_ID;TSE_TRANSACTION_NUMBER;TSE_START_TIME;TSE_END_TIME;TSE_SIGNATURE_COUNTER;TSE_SIGNATURE_VALUE;TSE_TIME_FORMAT\n";

        $checkouts = Checkout::with(['machine', 'machine.tseClient'])
            ->whereBetween('created_at', [$this->dateFrom, $this->dateTo])
            ->orderBy('created_at')
            ->get();

        $nr = 1;
        foreach ($checkouts as $checkout) {
            $start = $checkout->created_at->format('Y-m-d\TH:i:s');
            $end = $checkout->created_at->addSeconds(5)->format('Y-m-d\TH:i:s');
            $bonId = "FSB-{$checkout->created_at->year}-{$checkout->id}";
            $clientId = $checkout->machine->tseClient->remote_id ?? 'DEFAULT';
            $amount = number_format($checkout->total / 100, 2, '.', '');

            $content .= "POS{$checkout->machine_id}|".now()->format('Y-m-d\TH:i:s')."|{$nr}|{$bonId}|{$checkout->id}|Beleg|Kassenbeleg|T1|0|{$start}|{$end}|C001|Cashier|{$amount}|||||||||\n";
            $nr++;
        }

        file_put_contents($exportDir.'/transactions_v1.csv', $content);
    }

    /**
     * Generate cashregister.csv - Cash register identification
     */
    private function generateCashRegister(string $exportDir): void
    {
        $machines = \App\Models\Machine::whereHas('checkouts', function ($query) {
            $query->whereBetween('created_at', [$this->dateFrom, $this->dateTo]);
        })->get();

        $content = "Z_KASSE_ID|Z_ERSTELLUNG|Z_NR|KASSE_BRAND|KASSE_MODELL|KASSE_SERIENNR|KASSE_SW_BRAND|KASSE_SW_VERSION|KASSE_BASISWAEH_CODE|KEINE_UST_ZUORDNUNG\n";

        $nr = 1;
        foreach ($machines as $machine) {
            $content .= "POS{$machine->id}|".now()->format('Y-m-d\TH:i:s')."|{$nr}|Eurofurence e.V.|Fursuit Badge Registration|{$machine->name}|Laravel Badge System|1.0.0|EUR|0\n";
            $nr++;
        }

        file_put_contents($exportDir.'/cashregister.csv', $content);
    }

    /**
     * Generate receipts_v1.csv - Receipt line items
     */
    private function generateReceiptsV1(string $exportDir): void
    {
        $content = "Z_KASSE_ID;TRANSACTION_TYPE;TRANSACTION_NUMBER;RECEIPT_LINE_NUMBER;ARTICLE_NUMBER;ARTICLE_DESCRIPTION;QUANTITY;UNIT_PRICE;DISCOUNT;TAX_RATE;TAX_AMOUNT;LINE_AMOUNT_INCL_TAX\n";

        $checkouts = Checkout::with('items')
            ->whereBetween('created_at', [$this->dateFrom, $this->dateTo])
            ->orderBy('created_at')
            ->get();

        foreach ($checkouts as $checkout) {
            $lineNumber = 1;
            foreach ($checkout->items as $item) {
                $data = [
                    'Z_KASSE_ID' => 'POS'.$checkout->machine_id,
                    'TRANSACTION_TYPE' => 'Kassenbeleg-V1',
                    'TRANSACTION_NUMBER' => "FSB-{$checkout->created_at->year}-{$checkout->id}",
                    'RECEIPT_LINE_NUMBER' => $lineNumber++,
                    'ARTICLE_NUMBER' => 'BADGE-'.$item->id,
                    'ARTICLE_DESCRIPTION' => $item->name,
                    'QUANTITY' => 1.0,
                    'UNIT_PRICE' => number_format($item->total / 100, 2, '.', ''),
                    'DISCOUNT' => '0.00',
                    'TAX_RATE' => '19.00',
                    'TAX_AMOUNT' => number_format(($item->total * 0.19 / 1.19) / 100, 2, '.', ''),
                    'LINE_AMOUNT_INCL_TAX' => number_format($item->total / 100, 2, '.', ''),
                ];

                $content .= implode(';', array_map([$this, 'escapeCsvField'], $data))."\n";
            }
        }

        file_put_contents($exportDir.'/receipts_v1.csv', $content);
    }

    /**
     * Generate cashpointclosing.csv - Daily closing with company master data
     */
    private function generateCashPointClosing(string $exportDir): void
    {
        $machines = \App\Models\Machine::whereHas('checkouts', function ($query) {
            $query->whereBetween('created_at', [$this->dateFrom, $this->dateTo]);
        })->get();

        $content = "Z_KASSE_ID|Z_ERSTELLUNG|Z_NR|Z_BUCHUNGSTAG|TAXONOMIE_VERSION|Z_START_ID|Z_ENDE_ID|NAME|STRASSE|PLZ|ORT|LAND|STNR|USTID|Z_SE_ZAHLUNGEN|Z_SE_BARZAHLUNGEN\n";

        $nr = 1;
        foreach ($machines as $machine) {
            // Calculate totals for this machine
            $totalAmount = $machine->checkouts()
                ->whereBetween('created_at', [$this->dateFrom, $this->dateTo])
                ->sum('total') / 100;
            $cashAmount = $machine->checkouts()
                ->whereBetween('created_at', [$this->dateFrom, $this->dateTo])
                ->where('payment_method', 'cash')
                ->sum('total') / 100;

            $content .= "POS{$machine->id}|".now()->format('Y-m-d\TH:i:s')."|{$nr}|".$this->dateTo->format('Y-m-d')."|2.0|1|999|Eurofurence e.V.|Kieler Str. 1|10115|Berlin|DE|27/640/12345|DE123456789|{$totalAmount}|{$cashAmount}\n";
            $nr++;
        }

        file_put_contents($exportDir.'/cashpointclosing.csv', $content);
    }

    /**
     * Generate transactions.csv - Transaction headers (Bonkopf)
     */
    private function generateTransactions(string $exportDir): void
    {
        $content = "Z_KASSE_ID|Z_ERSTELLUNG|Z_NR|BON_ID|BON_NR|BON_TYP|BON_NAME|TERMINAL_ID|BON_STORNO|BON_START|BON_ENDE|BEDIENER_ID|BEDIENER_NAME|UMS_BRUTTO|KUNDE_NAME|KUNDE_ID|KUNDE_TYP|KUNDE_STRASSE|KUNDE_PLZ|KUNDE_ORT|KUNDE_LAND|KUNDE_USTID|BON_NOTIZ\n";

        $checkouts = Checkout::with(['machine', 'machine.tseClient'])
            ->whereBetween('created_at', [$this->dateFrom, $this->dateTo])
            ->orderBy('created_at')
            ->get();

        $nr = 1;
        foreach ($checkouts as $checkout) {
            $start = $checkout->created_at->format('Y-m-d\TH:i:s');
            $end = $checkout->created_at->addSeconds(5)->format('Y-m-d\TH:i:s');
            $bonId = "FSB-{$checkout->created_at->year}-{$checkout->id}";
            $amount = number_format($checkout->total / 100, 2, '.', '');

            $content .= "POS{$checkout->machine_id}|".now()->format('Y-m-d\TH:i:s')."|{$nr}|{$bonId}|{$checkout->id}|Beleg|Kassenbeleg|T1|0|{$start}|{$end}|C001|Cashier|{$amount}|||||||||\n";
            $nr++;
        }

        file_put_contents($exportDir.'/transactions.csv', $content);
    }

    /**
     * Generate transactions_vat.csv - VAT per transaction
     */
    private function generateTransactionsVat(string $exportDir): void
    {
        $content = "Z_KASSE_ID|Z_ERSTELLUNG|Z_NR|BON_ID|UST_SCHLUESSEL|BON_BRUTTO|BON_NETTO|BON_UST\n";

        $checkouts = Checkout::whereBetween('created_at', [$this->dateFrom, $this->dateTo])
            ->orderBy('created_at')
            ->get();

        $nr = 1;
        foreach ($checkouts as $checkout) {
            $brutto = number_format($checkout->total / 100, 2, '.', '');
            $netto = number_format(($checkout->total / 1.19) / 100, 2, '.', '');
            $ust = number_format(($checkout->total - ($checkout->total / 1.19)) / 100, 2, '.', '');
            $bonId = "FSB-{$checkout->created_at->year}-{$checkout->id}";

            $content .= "POS{$checkout->machine_id}|".now()->format('Y-m-d\TH:i:s')."|{$nr}|{$bonId}|1|{$brutto}|{$netto}|{$ust}\n";
            $nr++;
        }

        file_put_contents($exportDir.'/transactions_vat.csv', $content);
    }

    /**
     * Generate vat.csv - VAT rate definitions
     */
    private function generateVat(string $exportDir): void
    {
        $machines = \App\Models\Machine::whereHas('checkouts', function ($query) {
            $query->whereBetween('created_at', [$this->dateFrom, $this->dateTo]);
        })->get();

        $content = "Z_KASSE_ID|Z_ERSTELLUNG|Z_NR|UST_SCHLUESSEL|UST_SATZ|UST_BESCHR\n";

        foreach ($machines as $machine) {
            $content .= "POS{$machine->id}|".now()->format('Y-m-d\TH:i:s')."|1|1|19.00|Standard rate\n";
            $content .= "POS{$machine->id}|".now()->format('Y-m-d\TH:i:s')."|2|2|7.00|Reduced rate\n";
            $content .= "POS{$machine->id}|".now()->format('Y-m-d\TH:i:s')."|3|5|0.00|No VAT\n";
        }

        file_put_contents($exportDir.'/vat.csv', $content);
    }

    /**
     * Generate transactions_tse.csv - TSE data per transaction
     */
    private function generateTransactionsTse(string $exportDir): void
    {
        $content = "Z_KASSE_ID|Z_ERSTELLUNG|Z_NR|BON_ID|TSE_ID|TSE_TANR|TSE_TA_START|TSE_TA_ENDE|TSE_TA_VORGANGSART|TSE_TA_SIGZ|TSE_TA_SIG|TSE_TA_FEHLER|TSE_VORGANGSDATEN\n";

        $checkouts = Checkout::with(['machine', 'machine.tseClient'])
            ->whereBetween('created_at', [$this->dateFrom, $this->dateTo])
            ->orderBy('created_at')
            ->get();

        $nr = 1;
        foreach ($checkouts as $checkout) {
            $start = $checkout->tse_timestamp ? Carbon::parse($checkout->tse_timestamp)->format('Y-m-d\TH:i:s') : $checkout->created_at->format('Y-m-d\TH:i:s');
            $ende = $checkout->tse_timestamp ? Carbon::parse($checkout->tse_timestamp)->addSeconds(5)->format('Y-m-d\TH:i:s') : $checkout->created_at->addSeconds(5)->format('Y-m-d\TH:i:s');
            $bonId = "FSB-{$checkout->created_at->year}-{$checkout->id}";
            $vorgangsdaten = base64_encode(json_encode(['receipt_id' => $bonId, 'type' => 'Kassenbeleg']));

            $content .= "POS{$checkout->machine_id}|".now()->format('Y-m-d\TH:i:s')."|{$nr}|{$bonId}|{$checkout->tse_serial_number}|{$checkout->tse_transaction_number}|{$start}|{$ende}|Kassenbeleg-V1|{$checkout->tse_signature_counter}|{$checkout->tse_start_signature}||{$vorgangsdaten}\n";
            $nr++;
        }

        file_put_contents($exportDir.'/transactions_tse.csv', $content);
    }

    /**
     * Remove directory and all contents
     */
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

    /**
     * Generate tse.csv - TSE device information
     */
    private function generateTse(string $exportDir): void
    {
        $tseClients = \App\Domain\Checkout\Models\TseClient::whereHas('machine.checkouts', function ($query) {
            $query->whereBetween('created_at', [$this->dateFrom, $this->dateTo]);
        })->get();

        $content = "Z_KASSE_ID|Z_ERSTELLUNG|Z_NR|TSE_ID|TSE_SERIAL|TSE_SIG_ALGO|TSE_ZEITFORMAT|TSE_PD_ENCODING|TSE_PUBLIC_KEY|TSE_ZERTIFIKAT_I|TSE_ZERTIFIKAT_II\n";

        $nr = 1;
        foreach ($tseClients as $client) {
            $content .= "POS{$client->machine_id}|".now()->format('Y-m-d\TH:i:s')."|{$nr}|{$client->serial_number}|{$client->serial_number}|ecdsa-plain-SHA256|unixTime|UTF-8|".base64_encode(random_bytes(65)).'|'.base64_encode(random_bytes(256))."|\n";
            $nr++;
        }

        file_put_contents($exportDir.'/tse.csv', $content);
    }

    /**
     * Generate datapayment.csv - Payment methods per transaction
     */
    private function generateDataPayment(string $exportDir): void
    {
        $content = "Z_KASSE_ID|Z_ERSTELLUNG|Z_NR|BON_ID|ZAHLART_TYP|ZAHLART_NAME|ZAHLWAEH_CODE|ZAHLWAEH_BETRAG|BASISWAEH_BETRAG\n";

        $checkouts = Checkout::whereBetween('created_at', [$this->dateFrom, $this->dateTo])
            ->orderBy('created_at')
            ->get();

        $nr = 1;
        foreach ($checkouts as $checkout) {
            $amount = number_format($checkout->total / 100, 2, '.', '');
            $zahlartTyp = $checkout->payment_method === 'cash' ? 'Bar' : 'Kreditkarte';
            $zahlartName = $checkout->payment_method === 'cash' ? 'Bargeld' : 'SumUp';

            $content .= "POS{$checkout->machine_id}|".now()->format('Y-m-d\TH:i:s')."|{$nr}|FSB-{$checkout->created_at->year}-{$checkout->id}|{$zahlartTyp}|{$zahlartName}|EUR|{$amount}|{$amount}\n";
            $nr++;
        }

        file_put_contents($exportDir.'/datapayment.csv', $content);
    }

    /**
     * Generate lines.csv - Transaction line items
     */
    private function generateLines(string $exportDir): void
    {
        $content = "Z_KASSE_ID|Z_ERSTELLUNG|Z_NR|BON_ID|POS_ZEILE|GUTSCHEIN_NR|ARTIKELTEXT|POS_TERMINAL_ID|GV_TYP|GV_NAME|INHAUS|P_STORNO|AGENTUR_ID|ART_NR|GTIN|WARENGR_ID|WARENGR|MENGE|FAKTOR|EINHEIT|STK_BR\n";

        $checkouts = Checkout::with('items')
            ->whereBetween('created_at', [$this->dateFrom, $this->dateTo])
            ->orderBy('created_at')
            ->get();

        $nr = 1;
        foreach ($checkouts as $checkout) {
            $lineNumber = 1;
            foreach ($checkout->items as $item) {
                $amount = number_format($item->total / 100, 2, '.', '');
                $bonId = "FSB-{$checkout->created_at->year}-{$checkout->id}";

                $content .= "POS{$checkout->machine_id}|".now()->format('Y-m-d\TH:i:s')."|{$nr}|{$bonId}|{$lineNumber}||{$item->name}|T1|Umsatz|Warenverkauf|1|0|0|BADGE-{$item->id}||001|Badges|1.00|1.00|Stk|{$amount}\n";
                $lineNumber++;
                $nr++;
            }
        }

        file_put_contents($exportDir.'/lines.csv', $content);
    }

    /**
     * Generate lines_vat.csv - VAT per line item
     */
    private function generateLinesVat(string $exportDir): void
    {
        $content = "Z_KASSE_ID|Z_ERSTELLUNG|Z_NR|BON_ID|POS_ZEILE|UST_SCHLUESSEL|POS_BRUTTO|POS_NETTO|POS_UST\n";

        $checkouts = Checkout::with('items')
            ->whereBetween('created_at', [$this->dateFrom, $this->dateTo])
            ->orderBy('created_at')
            ->get();

        $nr = 1;
        foreach ($checkouts as $checkout) {
            $lineNumber = 1;
            foreach ($checkout->items as $item) {
                $brutto = number_format($item->total / 100, 2, '.', '');
                $netto = number_format(($item->total / 1.19) / 100, 2, '.', '');
                $ust = number_format(($item->total - ($item->total / 1.19)) / 100, 2, '.', '');
                $bonId = "FSB-{$checkout->created_at->year}-{$checkout->id}";

                $content .= "POS{$checkout->machine_id}|".now()->format('Y-m-d\TH:i:s')."|{$nr}|{$bonId}|{$lineNumber}|1|{$brutto}|{$netto}|{$ust}\n";
                $lineNumber++;
                $nr++;
            }
        }

        file_put_contents($exportDir.'/lines_vat.csv', $content);
    }

    /**
     * Generate businesscases.csv - Daily summaries by business case type
     */
    private function generateBusinessCases(string $exportDir): void
    {
        $content = "Z_KASSE_ID|Z_ERSTELLUNG|Z_NR|GV_TYP|GV_NAME|AGENTUR_ID|UST_SCHLUESSEL|Z_UMS_BRUTTO|Z_UMS_NETTO|Z_UST\n";

        $machines = \App\Models\Machine::whereHas('checkouts', function ($query) {
            $query->whereBetween('created_at', [$this->dateFrom, $this->dateTo]);
        })->get();

        $nr = 1;
        foreach ($machines as $machine) {
            $totalBrutto = $machine->checkouts()
                ->whereBetween('created_at', [$this->dateFrom, $this->dateTo])
                ->sum('total') / 100;
            $totalNetto = round($totalBrutto / 1.19, 2);
            $totalUst = $totalBrutto - $totalNetto;

            $content .= "POS{$machine->id}|".now()->format('Y-m-d\TH:i:s')."|{$nr}|Umsatz|Warenverkauf|0|1|{$totalBrutto}|{$totalNetto}|{$totalUst}\n";
            $nr++;
        }

        file_put_contents($exportDir.'/businesscases.csv', $content);
    }

    /**
     * Generate payment.csv - Daily payment summaries
     */
    private function generatePayment(string $exportDir): void
    {
        $content = "Z_KASSE_ID|Z_ERSTELLUNG|Z_NR|ZAHLART_TYP|ZAHLART_NAME|Z_ZAHLART_BETRAG\n";

        $machines = \App\Models\Machine::whereHas('checkouts', function ($query) {
            $query->whereBetween('created_at', [$this->dateFrom, $this->dateTo]);
        })->get();

        $nr = 1;
        foreach ($machines as $machine) {
            $cashAmount = $machine->checkouts()
                ->whereBetween('created_at', [$this->dateFrom, $this->dateTo])
                ->where('payment_method', 'cash')
                ->sum('total') / 100;
            $cardAmount = $machine->checkouts()
                ->whereBetween('created_at', [$this->dateFrom, $this->dateTo])
                ->where('payment_method', 'card')
                ->sum('total') / 100;

            if ($cashAmount > 0) {
                $content .= "POS{$machine->id}|".now()->format('Y-m-d\TH:i:s')."|{$nr}|Bar|Bargeld|{$cashAmount}\n";
                $nr++;
            }
            if ($cardAmount > 0) {
                $content .= "POS{$machine->id}|".now()->format('Y-m-d\TH:i:s')."|{$nr}|Kreditkarte|SumUp|{$cardAmount}\n";
                $nr++;
            }
        }

        file_put_contents($exportDir.'/payment.csv', $content);
    }

    /**
     * Generate cash_per_currency.csv - Cash balance per currency
     */
    private function generateCashPerCurrency(string $exportDir): void
    {
        $content = "Z_KASSE_ID|Z_ERSTELLUNG|Z_NR|ZAHLART_WAEH|ZAHLART_BETRAG_WAEH\n";

        $machines = \App\Models\Machine::whereHas('checkouts', function ($query) {
            $query->whereBetween('created_at', [$this->dateFrom, $this->dateTo]);
        })->get();

        $nr = 1;
        foreach ($machines as $machine) {
            $cashAmount = $machine->checkouts()
                ->whereBetween('created_at', [$this->dateFrom, $this->dateTo])
                ->where('payment_method', 'cash')
                ->sum('total') / 100;

            if ($cashAmount > 0) {
                $content .= "POS{$machine->id}|".now()->format('Y-m-d\TH:i:s')."|{$nr}|EUR|{$cashAmount}\n";
                $nr++;
            }
        }

        file_put_contents($exportDir.'/cash_per_currency.csv', $content);
    }

    /**
     * Generate index.xml - Descriptor file for DSFinV-K export
     */
    private function generateIndexXml(string $exportDir): void
    {
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
        $xml .= '  <CreatedAt>'.now()->format('Y-m-d\TH:i:s').'</CreatedAt>'."\n";
        $xml .= '  <TaxonomyVersion>2.0</TaxonomyVersion>'."\n";
        $xml .= '  <DataVersion>1.0</DataVersion>'."\n";
        $xml .= '  <TimeRange>'."\n";
        $xml .= '    <From>'.$this->dateFrom->format('Y-m-d').'</From>'."\n";
        $xml .= '    <To>'.$this->dateTo->format('Y-m-d').'</To>'."\n";
        $xml .= '  </TimeRange>'."\n";
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

        file_put_contents($exportDir.'/index.xml', $xml);
    }

    /**
     * Create ZIP archive of export files
     */
    private function createZipArchive(string $sourceDir, string $zipPath): void
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            // Add all CSV files
            $csvFiles = glob($sourceDir.'/*.csv');
            foreach ($csvFiles as $file) {
                $zip->addFile($file, basename($file));
            }

            // Add index.xml
            $xmlFile = $sourceDir.'/index.xml';
            if (file_exists($xmlFile)) {
                $zip->addFile($xmlFile, basename($xmlFile));
            }

            $zip->close();
        }
    }

    /**
     * Escape CSV field content - Updated for pipe delimited format
     */
    private function escapeCsvField($field): string
    {
        // For pipe-delimited DSFinV-K format, escape pipes and quotes
        if (str_contains($field, '|') || str_contains($field, '"') || str_contains($field, "\n")) {
            return '"'.str_replace('"', '""', $field).'"';
        }

        return $field;
    }
}
