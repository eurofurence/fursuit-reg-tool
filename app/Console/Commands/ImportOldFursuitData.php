<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\Fursuit\Fursuit;
use App\Models\Species;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

class ImportOldFursuitData extends Command
{
    protected $signature = 'import:old-fursuit-data 
                            {--dry-run : Run without making database changes}
                            {--event= : Import specific event only (e.g., EF17)}
                            {--limit= : Limit number of records to import}
                            {--skip-images : Skip image processing}';

    protected $description = 'Import old fursuit data from dataimport folder';

    private array $eventMapping = [
        'EF15' => [
            'name' => 'EF15',
            'theme' => '1001 Arabian Nights',
            'location' => 'Suhl, Germany',
            'year' => 2009,
            'starts_at' => '2009-08-26',
            'ends_at' => '2009-08-30',
        ],
        'EF16' => [
            'name' => 'EF16',
            'theme' => 'Serengeti',
            'location' => 'Magdeburg, Germany',
            'year' => 2010,
            'starts_at' => '2010-08-25',
            'ends_at' => '2010-08-29',
        ],
        'EF17' => [
            'name' => 'EF17',
            'theme' => 'Kung Fur Hustle',
            'location' => 'Magdeburg, Germany',
            'year' => 2011,
            'starts_at' => '2011-08-17',
            'ends_at' => '2011-08-21',
        ],
        'EF18' => [
            'name' => 'EF18',
            'theme' => 'Animalia Romana',
            'location' => 'Magdeburg, Germany',
            'year' => 2012,
            'starts_at' => '2012-08-22',
            'ends_at' => '2012-08-26',
        ],
        'EF19' => [
            'name' => 'EF19',
            'theme' => 'Aloha Hawaii',
            'location' => 'Magdeburg, Germany',
            'year' => 2013,
            'starts_at' => '2013-08-21',
            'ends_at' => '2013-08-25',
        ],
        'EF20' => [
            'name' => 'EF20',
            'theme' => 'Crime Scene Berlin',
            'location' => 'Berlin, Germany',
            'year' => 2014,
            'starts_at' => '2014-08-20',
            'ends_at' => '2014-08-24',
        ],
        'EF21' => [
            'name' => 'EF21',
            'theme' => 'Greenhouse World',
            'location' => 'Berlin, Germany',
            'year' => 2015,
            'starts_at' => '2015-08-19',
            'ends_at' => '2015-08-23',
        ],
        'EF22' => [
            'name' => 'EF22',
            'theme' => 'Back to the 80s',
            'location' => 'Berlin, Germany',
            'year' => 2016,
            'starts_at' => '2016-08-17',
            'ends_at' => '2016-08-21',
        ],
        'EF23' => [
            'name' => 'EF23',
            'theme' => 'Ancient Egypt',
            'location' => 'Berlin, Germany',
            'year' => 2017,
            'starts_at' => '2017-08-16',
            'ends_at' => '2017-08-20',
        ],
        'EF24' => [
            'name' => 'EF24',
            'theme' => 'Aviators - Conquer the Sky!',
            'location' => 'Berlin, Germany',
            'year' => 2018,
            'starts_at' => '2018-08-15',
            'ends_at' => '2018-08-19',
        ],
        'EF25' => [
            'name' => 'EF25',
            'theme' => 'Fractures in Time',
            'location' => 'Berlin, Germany',
            'year' => 2019,
            'starts_at' => '2019-08-21',
            'ends_at' => '2019-08-25',
            'archival_notice' => 'Ahoy matey! You are exploring archival waters - some images may have been cropped incorrectly.',
        ],
        'EF26' => [
            'name' => 'EF26',
            'theme' => 'Tortuga - On the High Seas',
            'location' => 'Berlin, Germany',
            'year' => 2022,
            'starts_at' => '2022-08-18',
            'ends_at' => '2022-08-22',
            'archival_notice' => 'Ahoy matey! You are exploring archival waters - some images may have been cropped incorrectly.',
        ],
        'EF27' => [
            'name' => 'EF27',
            'theme' => 'Black Magic',
            'location' => 'Hamburg, Germany',
            'year' => 2024,
            'starts_at' => '2024-08-31',
            'ends_at' => '2024-09-04',
            'archival_notice' => 'Ahoy matey! You are exploring archival waters - some images may have been cropped incorrectly.',
        ],
    ];

    private array $speciesNormalization = [
        // Common normalizations
        'wolf' => 'Wolf',
        'wolf-kitty hybrid' => 'Wolf-Cat Hybrid',
        'wolfhuskey' => 'Wolf-Husky Hybrid',
        'huskywolf' => 'Husky-Wolf Hybrid',
        'husky - wolf' => 'Husky-Wolf Hybrid',
        'wolfdog' => 'Wolf-Dog Hybrid',
        'blue dragon-fox' => 'Dragon-Fox Hybrid',
        'plushie dragon' => 'Dragon',
        'nomwolf' => 'Wolf',
        'anthro tiger' => 'Tiger',
        'snowtiger' => 'Snow Tiger',
        'polarfox' => 'Arctic Fox',
        'snowleopard' => 'Snow Leopard',
        'timber wolf' => 'Wolf',
        'polar wolf' => 'Wolf',
        'lupus sapiens' => 'Wolf',
        'german shepherd dog' => 'German Shepherd',
        'coyote - genius' => 'Coyote',
        'friesian horse' => 'Horse',
        'red panda' => 'Red Panda',
        'collie dog' => 'Collie',
        'border collie' => 'Border Collie',
        'arctic fox' => 'Arctic Fox',
    ];

    private int $importedCount = 0;

    private int $skippedCount = 0;

    private int $errorCount = 0;

    private array $createdEvents = [];

    private array $createdSpecies = [];

    public function handle(): int
    {
        $this->info('🎯 Starting Old Fursuit Data Import');
        $this->info('=====================================');

        $dataPath = base_path('dataimport');

        if (! is_dir($dataPath)) {
            $this->error("❌ Data import directory not found: {$dataPath}");

            return Command::FAILURE;
        }

        $csvPath = $dataPath.'/old.csv';
        if (! file_exists($csvPath)) {
            $this->error("❌ CSV file not found: {$csvPath}");

            return Command::FAILURE;
        }

        try {
            DB::beginTransaction();

            // Step 1: Create Events
            $this->createEvents();

            // Step 2: Import CSV Data
            $this->importCsvData($csvPath);

            // Step 3: Process HTML files for additional data
            if (! $this->option('skip-images')) {
                $this->processHtmlFiles($dataPath);
            }

            if ($this->option('dry-run')) {
                $this->warn('🔄 DRY RUN - Rolling back all changes');
                DB::rollBack();
            } else {
                DB::commit();
                $this->info('✅ Import completed successfully');
            }

            $this->displaySummary();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->error('❌ Import failed: '.$e->getMessage());
            Log::error('Fursuit import failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function createEvents(): void
    {
        $this->info('📅 Creating Events...');

        $specificEvent = $this->option('event');
        $eventsToCreate = $specificEvent ? [$specificEvent => $this->eventMapping[$specificEvent]] : $this->eventMapping;

        foreach ($eventsToCreate as $eventCode => $eventData) {
            $event = Event::updateOrCreate(
                ['name' => $eventData['name']],
                [
                    'starts_at' => $eventData['starts_at'],
                    'ends_at' => $eventData['ends_at'],
                    'order_starts_at' => $eventData['starts_at'].' 09:00:00',
                    'order_ends_at' => $eventData['ends_at'].' 18:00:00',
                    'archival_notice' => $eventData['archival_notice'] ?? null,
                    'catch_em_all_enabled' => false, // All historical imports have catch_em_all disabled
                ]
            );

            $this->createdEvents[$eventCode] = $event;

            if ($event->wasRecentlyCreated) {
                $this->line("  ✅ Created: {$eventData['name']} ({$eventData['theme']})");
            } else {
                $this->line("  ↻ Updated: {$eventData['name']}");
            }
        }
    }

    private function importCsvData(string $csvPath): void
    {
        $this->info('📊 Processing CSV Data...');

        $handle = fopen($csvPath, 'r');
        if (! $handle) {
            throw new \Exception("Cannot open CSV file: {$csvPath}");
        }

        // Skip header
        fgetcsv($handle);

        $bar = $this->output->createProgressBar();
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% | %message%');

        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $processed = 0;

        while (($data = fgetcsv($handle)) !== false && (! $limit || $processed < $limit)) {
            if (count($data) < 4) {
                $this->skippedCount++;

                continue;
            }

            $eventCode = trim($data[0]);
            $fursuitName = trim($data[1]);
            $speciesName = trim($data[2]);
            $imageFile = isset($data[3]) ? trim($data[3]) : '';
            $dontPublish = isset($data[4]) ? (bool) $data[4] : false;

            // Skip if specific event filter and doesn't match
            if ($this->option('event') && $eventCode !== $this->option('event')) {
                $this->skippedCount++;

                continue;
            }

            $bar->setMessage("Processing: {$fursuitName}".($imageFile ? ' (with image)' : ''));
            $bar->advance();

            try {
                $this->processFursuitEntry($eventCode, $fursuitName, $speciesName, $imageFile, ! $dontPublish);
                $this->importedCount++;
            } catch (\Exception $e) {
                $this->errorCount++;
                Log::warning('Failed to import fursuit', [
                    'event' => $eventCode,
                    'name' => $fursuitName,
                    'species' => $speciesName,
                    'error' => $e->getMessage(),
                ]);
            }

            $processed++;
        }

        $bar->finish();
        $this->newLine();
        fclose($handle);
    }

    private function processFursuitEntry(string $eventCode, string $fursuitName, string $speciesName, string $imageFile, bool $published): void
    {
        if (empty($fursuitName) || empty($eventCode)) {
            throw new \Exception('Missing required fursuit name or event code');
        }

        // Get or create event
        if (! isset($this->createdEvents[$eventCode])) {
            throw new \Exception("Event not found: {$eventCode}");
        }
        $event = $this->createdEvents[$eventCode];

        // Normalize and get/create species
        $normalizedSpecies = $this->normalizeSpecies($speciesName);
        $species = $this->getOrCreateSpecies($normalizedSpecies);

        // Process image if available
        $imagePaths = null;
        if (! empty($imageFile) && $imageFile !== '""') {
            $imagePaths = $this->determineImagePath($eventCode, $imageFile);
        }

        // Determine if catch-em-all should be enabled (only for EF28 and onwards)
        // Catch-em-all started from EF28, so all historical events (EF15-EF27) should be disabled
        $enableCatchEmAll = false; // All historical imports should have catch_em_all disabled

        // Check if fursuit already exists
        $existingFursuit = Fursuit::where('name', $fursuitName)
            ->where('event_id', $event->id)
            ->first();

        if ($existingFursuit) {
            // Update existing fursuit
            $updateData = [
                'species_id' => $species->id,
                'published' => $published,
                'status' => 'approved', // Old system data is assumed approved
                'catch_em_all' => $enableCatchEmAll,
            ];

            // Add image paths if available
            if ($imagePaths) {
                $updateData['image'] = $imagePaths['image'];
                $updateData['image_webp'] = $imagePaths['image_webp'];
            }

            $existingFursuit->update($updateData);
        } else {
            // Create new fursuit without user (historical data)
            $createData = [
                'name' => $fursuitName,
                'species_id' => $species->id,
                'event_id' => $event->id,
                'user_id' => null, // Historical fursuits don't need users
                'status' => 'approved',
                'published' => $published,
                'catch_em_all' => $enableCatchEmAll,
                'image' => $imagePaths ? $imagePaths['image'] : null,
                'image_webp' => $imagePaths ? $imagePaths['image_webp'] : null,
            ];

            Fursuit::create($createData);
        }
    }

    private function normalizeSpecies(string $species): string
    {
        $species = trim($species);
        $lowered = strtolower($species);

        return $this->speciesNormalization[$lowered] ?? ucfirst($species);
    }

    private function getOrCreateSpecies(string $speciesName): Species
    {
        if (isset($this->createdSpecies[$speciesName])) {
            return $this->createdSpecies[$speciesName];
        }

        $species = Species::firstOrCreate(['name' => $speciesName]);
        $this->createdSpecies[$speciesName] = $species;

        return $species;
    }

    private function determineImagePath(string $eventCode, string $imageFile): ?array
    {
        if (empty($imageFile) || $imageFile === '""') {
            return null;
        }

        // Try to find and process the image file
        $localPaths = [
            "dataimport/{$eventCode}/suitpics/{$imageFile}",
            "dataimport/{$eventCode}/images/{$imageFile}",
            "dataimport/{$eventCode}/{$imageFile}",
        ];

        foreach ($localPaths as $localPath) {
            $fullPath = base_path($localPath);

            if (file_exists($fullPath)) {
                try {
                    return $this->processAndUploadImage($fullPath, $eventCode, $imageFile);
                } catch (\Exception $e) {
                    // Log the error and fail the import for this fursuit
                    Log::error('Image processing failed during import', [
                        'event' => $eventCode,
                        'image_file' => $imageFile,
                        'local_path' => $fullPath,
                        'error' => $e->getMessage(),
                    ]);
                    throw new \Exception("Image processing failed for {$imageFile}: ".$e->getMessage());
                }
            }
        }

        // If no image file found, log it but don't fail the import
        Log::info('Image file not found during import', [
            'event' => $eventCode,
            'image_file' => $imageFile,
            'searched_paths' => $localPaths,
        ]);

        return null;
    }

    private function processAndUploadImage(string $fullPath, string $eventCode, string $imageFile): array
    {
        $manager = new ImageManager(new Driver);

        // Read the original image
        $image = $manager->read($fullPath);

        // Target max dimensions (576x768)
        $maxWidth = 576;
        $maxHeight = 768;

        // Resize image to fit within max dimensions without stretching
        // This maintains aspect ratio and scales down if needed
        $image = $image->cover($maxWidth, $maxHeight, 'center');

        // Generate filenames
        $baseFilename = pathinfo($imageFile, PATHINFO_FILENAME).'_'.time();

        // Original file (keep original format for compatibility)
        $originalFilename = 'imported/'.$eventCode.'/'.$baseFilename.'.'.pathinfo($imageFile, PATHINFO_EXTENSION);
        $originalS3Path = 'fursuits/'.$originalFilename;

        // WebP version
        $webpFilename = 'imported/'.$eventCode.'/'.$baseFilename.'.webp';
        $webpS3Path = 'fursuits/'.$webpFilename;

        // Upload original (processed) image
        $originalContent = (string) $image->encode();
        $originalUploaded = Storage::put($originalS3Path, $originalContent);

        if (! $originalUploaded) {
            throw new \Exception("Failed to upload original image to S3: {$originalS3Path}");
        }

        // Upload WebP version
        $webpContent = (string) $image->toWebp(80); // 80% quality for good compression
        $webpUploaded = Storage::put($webpS3Path, $webpContent);

        if (! $webpUploaded) {
            throw new \Exception("Failed to upload WebP image to S3: {$webpS3Path}");
        }

        $this->line("  📁 Processed & Uploaded: {$imageFile} → {$originalS3Path} & {$webpS3Path} ({$image->width()}x{$image->height()})");

        return [
            'image' => $originalS3Path,
            'image_webp' => $webpS3Path,
        ];
    }

    private function processHtmlFiles(string $dataPath): void
    {
        $this->info('🌐 Processing HTML files for additional data...');

        $specificEvent = $this->option('event');
        $eventCodes = $specificEvent ? [$specificEvent] : array_keys($this->eventMapping);

        foreach ($eventCodes as $eventCode) {
            $eventDir = $dataPath.'/'.$eventCode;
            if (! is_dir($eventDir)) {
                continue;
            }

            $htmlFiles = glob($eventDir.'/details*.html');
            if (empty($htmlFiles)) {
                continue;
            }

            $this->line("  📂 Processing {$eventCode}: ".count($htmlFiles).' HTML files');

            foreach ($htmlFiles as $htmlFile) {
                try {
                    $this->extractDataFromHtml($htmlFile, $eventCode);
                } catch (\Exception $e) {
                    Log::warning('Failed to process HTML file', [
                        'file' => $htmlFile,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    private function extractDataFromHtml(string $htmlFile, string $eventCode): void
    {
        $content = file_get_contents($htmlFile);
        if (! $content) {
            return;
        }

        // Extract fursuit name from title or content
        preg_match('/Fursuit Details Page for (.+?)(?:<|\n|$)/i', $content, $nameMatches);
        if (! $nameMatches) {
            return;
        }

        $fursuitName = trim($nameMatches[1]);

        // Extract additional information if needed
        preg_match('/Species:<\/font>.*?<td[^>]*>([^<]+)/is', $content, $speciesMatches);
        preg_match('/Worn by:<\/font>.*?<td[^>]*>([^<]+)/is', $content, $ownerMatches);

        $species = isset($speciesMatches[1]) ? trim(strip_tags($speciesMatches[1])) : null;
        $owner = isset($ownerMatches[1]) ? trim(strip_tags($ownerMatches[1])) : null;

        // Update fursuit with additional data if found
        if (! empty($fursuitName) && isset($this->createdEvents[$eventCode])) {
            $fursuit = Fursuit::where('name', $fursuitName)
                ->where('event_id', $this->createdEvents[$eventCode]->id)
                ->first();

            if ($fursuit && ($species || $owner)) {
                $updates = [];

                if ($species && $species !== $fursuit->species->name) {
                    $normalizedSpecies = $this->normalizeSpecies($species);
                    $speciesModel = $this->getOrCreateSpecies($normalizedSpecies);
                    $updates['species_id'] = $speciesModel->id;
                }

                if (! empty($updates)) {
                    $fursuit->update($updates);
                }
            }
        }
    }

    private function displaySummary(): void
    {
        $this->newLine();
        $this->info('📊 Import Summary');
        $this->info('================');
        $this->line("✅ Imported: {$this->importedCount} fursuits");
        $this->line("⏭️  Skipped: {$this->skippedCount} entries");
        $this->line("❌ Errors: {$this->errorCount} entries");
        $this->line('📅 Events: '.count($this->createdEvents));
        $this->line('🏷️  Species: '.count($this->createdSpecies));

        if ($this->errorCount > 0) {
            $this->warn('⚠️  Check logs for error details');
        }

        if ($this->option('dry-run')) {
            $this->warn('🔄 This was a DRY RUN - no data was actually imported');
        }
    }
}
