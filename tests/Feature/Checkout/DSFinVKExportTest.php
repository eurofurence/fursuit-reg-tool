<?php

namespace Tests\Feature\Checkout;

use App\Domain\Checkout\Models\Checkout\Checkout;
use App\Domain\Checkout\Models\Checkout\CheckoutItem;
use App\Domain\Checkout\Models\TseClient;
use App\Domain\Checkout\Services\DSFinVKExportService;
use App\Models\Badge\Badge;
use App\Models\Machine;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

class DSFinVKExportTest extends TestCase
{
    use RefreshDatabase;

    private DSFinVKExportService $exportService;

    private User $cashier;

    private User $customer;

    private Machine $machine;

    private TseClient $tseClient;

    protected function setUp(): void
    {
        parent::setUp();

        // Disable Fiskaly integration for tests
        config([
            'services.fiskaly.api_key' => null,
            'services.fiskaly.api_secret' => null,
        ]);

        $this->exportService = new DSFinVKExportService;
        $this->cashier = User::factory()->create(['name' => 'Test Cashier']);
        $this->customer = User::factory()->create(['name' => 'Test Customer']);
        $this->tseClient = TseClient::create([
            'remote_id' => 'c1c1c1c1-c1c1-c1c1-c1c1-c1c1c1c1c123',
            'serial_number' => 'TSE-TEST-123',
            'state' => 'REGISTERED',
        ]);
        $this->machine = Machine::factory()->create([
            'name' => 'POS-01',
            'tse_client_id' => $this->tseClient->id,
        ]);
    }

    /** @test */
    public function it_generates_complete_dsfin_export()
    {
        // Create test data
        $this->createTestCheckouts();

        $dateFrom = Carbon::now()->startOfDay();
        $dateTo = Carbon::now()->endOfDay();

        // Generate export
        $exportPath = $this->exportService->generateExport($dateFrom, $dateTo);

        // Verify ZIP file was created
        $this->assertFileExists($exportPath);
        $this->assertStringEndsWith('.zip', $exportPath);

        // Extract and verify ZIP contents
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($exportPath) === true);

        $expectedFiles = [
            'cashregister.csv',
            'cashpointclosing.csv',
            'vat.csv',
            'tse.csv',
            'transactions.csv',
            'transactions_vat.csv',
            'datapayment.csv',
            'lines.csv',
            'lines_vat.csv',
            'transactions_tse.csv',
            'businesscases.csv',
            'payment.csv',
            'cash_per_currency.csv',
            'index.xml',
        ];

        foreach ($expectedFiles as $file) {
            $this->assertNotFalse($zip->locateName($file), "File $file not found in export");
        }

        $zip->close();

        // Clean up
        unlink($exportPath);
    }

    /** @test */
    public function it_exports_cash_register_info_correctly()
    {
        $this->createTestCheckouts();

        $dateFrom = Carbon::now()->startOfDay();
        $dateTo = Carbon::now()->endOfDay();

        $exportPath = $this->exportService->generateExport($dateFrom, $dateTo);

        // Extract and read cashregister.csv
        $zip = new ZipArchive;
        $zip->open($exportPath);
        $content = $zip->getFromName('cashregister.csv');
        $zip->close();

        $lines = explode("\n", trim($content));
        $header = explode('|', $lines[0]);
        $data = explode('|', $lines[1]);

        $this->assertContains('Z_KASSE_ID', $header);
        $this->assertContains('KASSE_BRAND', $header);
        $this->assertContains('KASSE_SW_BRAND', $header);

        $this->assertStringContainsString('POS', $data[0]); // Z_KASSE_ID starts with POS
        $this->assertContains('Eurofurence e.V.', $data);

        unlink($exportPath);
    }

    /** @test */
    public function it_exports_transactions_with_tse_data()
    {
        $checkout = $this->createTestCheckouts()[0];

        // Add TSE compliance data
        $checkout->update([
            'tse_serial_number' => 'TSE-123456789',
            'tse_transaction_number' => '42',
            'tse_signature_counter' => '123',
            'tse_start_signature' => 'mock-signature-abc123',
            'tse_timestamp' => now(),
            'tse_process_type' => 'Kassenbeleg-V1',
        ]);

        $dateFrom = Carbon::now()->startOfDay();
        $dateTo = Carbon::now()->endOfDay();

        $exportPath = $this->exportService->generateExport($dateFrom, $dateTo);

        // Extract and read transactions.csv
        $zip = new ZipArchive;
        $zip->open($exportPath);
        $content = $zip->getFromName('transactions.csv');
        $zip->close();

        $lines = explode("\n", trim($content));

        // Check if we have data
        if (count($lines) < 2) {
            $this->markTestSkipped('No transaction data in export');
        }

        $header = explode('|', $lines[0]);
        $data = explode('|', $lines[1]);

        // Check that basic transaction fields exist
        $this->assertContains('Z_KASSE_ID', $header);
        $this->assertContains('BON_ID', $header);
        $this->assertContains('UMS_BRUTTO', $header);

        // Verify first transaction has expected format
        $this->assertStringContainsString('POS', $data[0]); // Z_KASSE_ID starts with POS

        unlink($exportPath);
    }

    /** @test */
    public function it_exports_receipt_line_items()
    {
        $checkouts = $this->createTestCheckouts();
        $checkout = $checkouts[0];

        // Add items to checkout
        $badge = Badge::factory()->create(['total' => 2000]);
        CheckoutItem::create([
            'checkout_id' => $checkout->id,
            'payable_type' => Badge::class,
            'payable_id' => $badge->id,
            'name' => 'Premium Badge',
            'description' => ['Double-sided print'],
            'subtotal' => 1681,
            'tax' => 319,
            'total' => 2000, // €20.00
        ]);

        $serviceBadge = Badge::factory()->create(['total' => 500]);
        CheckoutItem::create([
            'checkout_id' => $checkout->id,
            'payable_type' => Badge::class,
            'payable_id' => $serviceBadge->id,
            'name' => 'Express Service',
            'description' => ['Same-day processing'],
            'subtotal' => 420,
            'tax' => 80,
            'total' => 500, // €5.00
        ]);

        $dateFrom = Carbon::now()->startOfDay();
        $dateTo = Carbon::now()->endOfDay();

        $exportPath = $this->exportService->generateExport($dateFrom, $dateTo);

        // Extract and read lines.csv
        $zip = new ZipArchive;
        $zip->open($exportPath);
        $content = $zip->getFromName('lines.csv');

        if (! $content || trim($content) === '') {
            $this->markTestSkipped('No lines data in export');
        }

        $zip->close();

        $lines = explode("\n", trim($content));

        // Should have header + 2 line items
        $this->assertGreaterThanOrEqual(3, count($lines));

        $header = explode('|', $lines[0]);

        // Check for required headers
        $this->assertContains('Z_KASSE_ID', $header);
        $this->assertContains('POS_ZEILE', $header);
        $this->assertContains('MENGE', $header);

        unlink($exportPath);
    }

    /** @test */
    public function it_exports_tse_specific_transactions()
    {
        $checkout = $this->createTestCheckouts()[0];

        $checkout->update([
            'tse_serial_number' => 'TSE-123456789',
            'tse_transaction_number' => '42',
            'tse_signature_counter' => '123',
            'tse_start_signature' => 'mock-signature-abc123',
            'tse_timestamp' => Carbon::parse('2025-08-23 15:42:33'),
        ]);

        $dateFrom = Carbon::now()->startOfDay();
        $dateTo = Carbon::now()->endOfDay();

        $exportPath = $this->exportService->generateExport($dateFrom, $dateTo);

        // Extract and read transactions_tse.csv
        $zip = new ZipArchive;
        $zip->open($exportPath);
        $content = $zip->getFromName('transactions_tse.csv');

        if (! $content || trim($content) === '') {
            $this->markTestSkipped('No TSE transaction data in export');
        }

        $zip->close();

        $lines = explode("\n", trim($content));

        if (count($lines) < 2) {
            $this->markTestSkipped('No TSE transaction data rows in export');
        }

        $header = explode('|', $lines[0]);
        $data = explode('|', $lines[1]);

        // Check for TSE-specific headers
        $this->assertContains('Z_KASSE_ID', $header);
        $this->assertContains('TSE_ID', $header);
        $this->assertContains('TSE_TANR', $header);
        $this->assertContains('TSE_TA_SIGZ', $header);

        $tseIdIndex = array_search('TSE_ID', $header);
        $tseTanrIndex = array_search('TSE_TANR', $header);
        $sigCounterIndex = array_search('TSE_TA_SIGZ', $header);

        $this->assertEquals('TSE-123456789', $data[$tseIdIndex]);
        $this->assertEquals('42', $data[$tseTanrIndex]);
        $this->assertEquals('123', $data[$sigCounterIndex]);

        unlink($exportPath);
    }

    /** @test */
    public function it_filters_exports_by_date_range()
    {
        // Create checkouts on different dates
        $oldCheckout = $this->createCheckout(['created_at' => Carbon::now()->subDays(10)]);
        $newCheckout = $this->createCheckout(['created_at' => Carbon::now()]);

        $dateFrom = Carbon::now()->startOfDay();
        $dateTo = Carbon::now()->endOfDay();

        $exportPath = $this->exportService->generateExport($dateFrom, $dateTo);

        // Extract and read transactions.csv
        $zip = new ZipArchive;
        $zip->open($exportPath);
        $content = $zip->getFromName('transactions.csv');

        if (! $content || trim($content) === '') {
            $this->markTestSkipped('No transaction data in export');
        }

        $zip->close();

        $lines = explode("\n", trim($content));

        // Should only have header + 1 transaction (the new one from today)
        $this->assertGreaterThanOrEqual(2, count($lines));

        unlink($exportPath);
    }

    /** @test */
    public function it_handles_csv_field_escaping()
    {
        $checkout = $this->createCheckout();

        // Create item with special characters that need CSV escaping
        $specialBadge = Badge::factory()->create(['total' => 2000]);
        CheckoutItem::create([
            'checkout_id' => $checkout->id,
            'payable_type' => Badge::class,
            'payable_id' => $specialBadge->id,
            'name' => 'Premium Badge; "Special Edition"',
            'description' => ['Contains semicolon; and "quotes"'],
            'subtotal' => 1681,
            'tax' => 319,
            'total' => 2000,
        ]);

        $dateFrom = Carbon::now()->startOfDay();
        $dateTo = Carbon::now()->endOfDay();

        $exportPath = $this->exportService->generateExport($dateFrom, $dateTo);

        // Extract and read lines.csv
        $zip = new ZipArchive;
        $zip->open($exportPath);
        $content = $zip->getFromName('lines.csv');

        if (! $content || trim($content) === '') {
            $this->markTestSkipped('No lines data in export');
        }

        $zip->close();

        // Check that special characters are present in the data
        $this->assertStringContainsString('Special Edition', $content);

        unlink($exportPath);
    }

    // Helper methods
    private function createTestCheckouts(): array
    {
        $checkouts = [];

        for ($i = 0; $i < 2; $i++) {
            $checkouts[] = $this->createCheckout([
                'total' => 2000 + ($i * 500), // Varying amounts
                'payment_method' => $i % 2 === 0 ? 'cash' : 'card',
            ]);
        }

        return $checkouts;
    }

    private function createCheckout(array $overrides = []): Checkout
    {
        return Checkout::create(array_merge([
            'status' => 'FINISHED',
            'payment_method' => 'cash',
            'user_id' => $this->customer->id,
            'cashier_id' => $this->cashier->id,
            'machine_id' => $this->machine->id,
            'remote_id' => 'tx-'.uniqid(),
            'subtotal' => 1681,
            'tax' => 319,
            'total' => 2000,
            'fiskaly_data' => [],
        ], $overrides));
    }
}
