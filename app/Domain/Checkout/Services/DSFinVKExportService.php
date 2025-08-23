<?php

namespace App\Domain\Checkout\Services;

use App\Domain\Checkout\Models\Checkout\Checkout;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
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
        if (!file_exists($this->exportPath)) {
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
        
        $exportId = 'dsfin_export_' . now()->format('Y_m_d_H_i_s');
        $exportDir = $this->exportPath . '/' . $exportId;
        mkdir($exportDir, 0755, true);

        // Generate all required CSV files
        $this->generateCashRegisterInfo($exportDir);
        $this->generateTransactionsV1($exportDir);
        $this->generateBusinessCasesV1($exportDir);
        $this->generateReceiptsV1($exportDir);
        $this->generateTaxRatesV1($exportDir);
        $this->generatePaymentTypesV1($exportDir);
        $this->generateTseTransactions($exportDir);
        
        // Create ZIP archive
        $zipPath = $this->exportPath . '/' . $exportId . '.zip';
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
        $data = [
            'CASH_REGISTER_TYPE' => 'REGISTRIERKASSE',
            'CASH_REGISTER_MANUFACTURER' => 'Eurofurence e.V.',
            'CASH_REGISTER_MODEL' => 'Fursuit Badge Registration',
            'CASH_REGISTER_SOFTWARE' => 'Laravel Badge System',
            'CASH_REGISTER_SOFTWARE_VERSION' => '1.0.0',
            'CASH_REGISTER_BASE_CURRENCY_CODE' => 'EUR',
            'CASH_REGISTER_START_DATE' => $this->dateFrom->format('Y-m-d'),
            'CASH_REGISTER_END_DATE' => $this->dateTo->format('Y-m-d'),
        ];

        $content = "CASH_REGISTER_TYPE;CASH_REGISTER_MANUFACTURER;CASH_REGISTER_MODEL;CASH_REGISTER_SOFTWARE;CASH_REGISTER_SOFTWARE_VERSION;CASH_REGISTER_BASE_CURRENCY_CODE;CASH_REGISTER_START_DATE;CASH_REGISTER_END_DATE\n";
        $content .= implode(';', array_values($data)) . "\n";

        file_put_contents($exportDir . '/cash_register_info.csv', $content);
    }

    /**
     * Generate transactions_v1.csv - Main transaction data
     */
    private function generateTransactionsV1(string $exportDir): void
    {
        $content = "TRANSACTION_TYPE;TRANSACTION_NUMBER;TRANSACTION_DATE;TRANSACTION_TIME;CLIENT_ID;PROCESS_TYPE;PROCESS_DATA;TSE_ID;TSE_TRANSACTION_NUMBER;TSE_START_TIME;TSE_END_TIME;TSE_SIGNATURE_COUNTER;TSE_SIGNATURE_VALUE;TSE_TIME_FORMAT\n";

        $checkouts = Checkout::whereBetween('created_at', [$this->dateFrom, $this->dateTo])
            ->orderBy('created_at')
            ->get();

        foreach ($checkouts as $checkout) {
            $data = [
                'TRANSACTION_TYPE' => 'Kassenbeleg-V1',
                'TRANSACTION_NUMBER' => "FSB-{$checkout->created_at->year}-{$checkout->id}",
                'TRANSACTION_DATE' => $checkout->created_at->format('Y-m-d'),
                'TRANSACTION_TIME' => $checkout->created_at->format('H:i:s'),
                'CLIENT_ID' => $checkout->machine->tseClient->remote_id ?? 'DEFAULT',
                'PROCESS_TYPE' => $checkout->tse_process_type ?? 'Kassenbeleg-V1',
                'PROCESS_DATA' => $checkout->tse_process_data ?? '',
                'TSE_ID' => $checkout->tse_serial_number ?? '',
                'TSE_TRANSACTION_NUMBER' => $checkout->tse_transaction_number ?? '',
                'TSE_START_TIME' => $checkout->tse_timestamp ? Carbon::parse($checkout->tse_timestamp)->format('Y-m-d\TH:i:s.v\Z') : '',
                'TSE_END_TIME' => $checkout->tse_timestamp ? Carbon::parse($checkout->tse_timestamp)->addSeconds(1)->format('Y-m-d\TH:i:s.v\Z') : '',
                'TSE_SIGNATURE_COUNTER' => $checkout->tse_signature_counter ?? '',
                'TSE_SIGNATURE_VALUE' => $checkout->tse_start_signature ?? '',
                'TSE_TIME_FORMAT' => 'unixTime',
            ];

            $content .= implode(';', array_map([$this, 'escapeCsvField'], $data)) . "\n";
        }

        file_put_contents($exportDir . '/transactions_v1.csv', $content);
    }

    /**
     * Generate business_cases_v1.csv - Business case mapping
     */
    private function generateBusinessCasesV1(string $exportDir): void
    {
        $content = "BUSINESS_CASE_TYPE;BUSINESS_CASE_NAME;BUSINESS_CASE_DESCRIPTION\n";
        $content .= "Kassenbeleg-V1;Badge Sale;Fursuit Badge Registration and Sale\n";
        
        file_put_contents($exportDir . '/business_cases_v1.csv', $content);
    }

    /**
     * Generate receipts_v1.csv - Receipt line items
     */
    private function generateReceiptsV1(string $exportDir): void
    {
        $content = "TRANSACTION_TYPE;TRANSACTION_NUMBER;RECEIPT_LINE_NUMBER;ARTICLE_NUMBER;ARTICLE_DESCRIPTION;QUANTITY;UNIT_PRICE;DISCOUNT;TAX_RATE;TAX_AMOUNT;LINE_AMOUNT_INCL_TAX\n";

        $checkouts = Checkout::with('items')
            ->whereBetween('created_at', [$this->dateFrom, $this->dateTo])
            ->orderBy('created_at')
            ->get();

        foreach ($checkouts as $checkout) {
            $lineNumber = 1;
            foreach ($checkout->items as $item) {
                $data = [
                    'TRANSACTION_TYPE' => 'Kassenbeleg-V1',
                    'TRANSACTION_NUMBER' => "FSB-{$checkout->created_at->year}-{$checkout->id}",
                    'RECEIPT_LINE_NUMBER' => $lineNumber++,
                    'ARTICLE_NUMBER' => 'BADGE-' . $item->id,
                    'ARTICLE_DESCRIPTION' => $item->name,
                    'QUANTITY' => 1.0,
                    'UNIT_PRICE' => number_format($item->total / 100, 2, '.', ''),
                    'DISCOUNT' => '0.00',
                    'TAX_RATE' => '19.00',
                    'TAX_AMOUNT' => number_format(($item->total * 0.19 / 1.19) / 100, 2, '.', ''),
                    'LINE_AMOUNT_INCL_TAX' => number_format($item->total / 100, 2, '.', ''),
                ];

                $content .= implode(';', array_map([$this, 'escapeCsvField'], $data)) . "\n";
            }
        }

        file_put_contents($exportDir . '/receipts_v1.csv', $content);
    }

    /**
     * Generate tax_rates_v1.csv - Tax rate definitions
     */
    private function generateTaxRatesV1(string $exportDir): void
    {
        $content = "TAX_RATE;TAX_RATE_DESCRIPTION;TAX_RATE_PERCENTAGE\n";
        $content .= "NORMAL;Standard Tax Rate;19.00\n";
        
        file_put_contents($exportDir . '/tax_rates_v1.csv', $content);
    }

    /**
     * Generate payment_types_v1.csv - Payment method definitions
     */
    private function generatePaymentTypesV1(string $exportDir): void
    {
        $content = "PAYMENT_TYPE;PAYMENT_TYPE_DESCRIPTION;PAYMENT_TYPE_CURRENCY_CODE\n";
        $content .= "CASH;Cash Payment;EUR\n";
        $content .= "CARD;Card Payment;EUR\n";
        
        file_put_contents($exportDir . '/payment_types_v1.csv', $content);
    }

    /**
     * Generate tse_transactions.csv - TSE specific data
     */
    private function generateTseTransactions(string $exportDir): void
    {
        $content = "TSE_TRANSACTION_NUMBER;TSE_TIMESTAMP;TSE_SIGNATURE_COUNTER;TSE_SIGNATURE_VALUE;TSE_SERIAL_NUMBER;CLIENT_ID\n";

        $checkouts = Checkout::whereBetween('created_at', [$this->dateFrom, $this->dateTo])
            ->whereNotNull('tse_transaction_number')
            ->orderBy('created_at')
            ->get();

        foreach ($checkouts as $checkout) {
            $data = [
                'TSE_TRANSACTION_NUMBER' => $checkout->tse_transaction_number ?? '',
                'TSE_TIMESTAMP' => $checkout->tse_timestamp ? Carbon::parse($checkout->tse_timestamp)->format('Y-m-d\TH:i:s.v\Z') : '',
                'TSE_SIGNATURE_COUNTER' => $checkout->tse_signature_counter ?? '',
                'TSE_SIGNATURE_VALUE' => $checkout->tse_start_signature ?? '',
                'TSE_SERIAL_NUMBER' => $checkout->tse_serial_number ?? '',
                'CLIENT_ID' => $checkout->machine->tseClient->remote_id ?? 'DEFAULT',
            ];

            $content .= implode(';', array_map([$this, 'escapeCsvField'], $data)) . "\n";
        }

        file_put_contents($exportDir . '/tse_transactions.csv', $content);
    }

    /**
     * Create ZIP archive of export files
     */
    private function createZipArchive(string $sourceDir, string $zipPath): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
            $files = glob($sourceDir . '/*.csv');
            foreach ($files as $file) {
                $zip->addFile($file, basename($file));
            }
            $zip->close();
        }
    }

    /**
     * Remove directory and all contents
     */
    private function removeDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != "." && $object != "..") {
                    if (is_dir($dir . "/" . $object) && !is_link($dir . "/" . $object)) {
                        $this->removeDirectory($dir . "/" . $object);
                    } else {
                        unlink($dir . "/" . $object);
                    }
                }
            }
            rmdir($dir);
        }
    }

    /**
     * Escape CSV field content
     */
    private function escapeCsvField($field): string
    {
        if (str_contains($field, ';') || str_contains($field, '"') || str_contains($field, "\n")) {
            return '"' . str_replace('"', '""', $field) . '"';
        }
        return $field;
    }
}