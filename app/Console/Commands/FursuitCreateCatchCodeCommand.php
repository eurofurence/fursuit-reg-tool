<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\Fursuit\Fursuit;
use App\Services\FursuitCatchCode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FursuitCreateCatchCodeCommand extends Command
{
    protected $signature = 'fursuit:create-catch-code {--purge-all : Purge all existing catch codes before generating new ones}';

    protected $description = 'Generate catch codes for fursuits participating in catch-em-all game';

    // Code which should be run once to generate all missing codes
    // Further Fursuit Creation/Updates should be caught by an observer
    // Will fill catch_em_all_code of fursuits who choose to participate
    public function handle(): void
    {
        $activeEvent = Event::getActiveEvent();
        
        if (!$activeEvent) {
            $this->error('No active event found.');
            return;
        }

        $this->info("Working with active event: {$activeEvent->name}");

        DB::transaction(function () use ($activeEvent) {
            // to purge all codes and build new
            if ($this->option('purge-all')) {
                $activeEvent->fursuits()->update(['catch_code' => null]);
                $this->info('All existing catch codes for the current event have been purged.');
            }

            // Get fursuits that need catch codes
            $fursuitsThatNeedCodes = $activeEvent->fursuits()
                ->where('catch_em_all', true)
                ->whereNull('catch_code')
                ->get();

            if ($fursuitsThatNeedCodes->isEmpty()) {
                $this->info('No fursuits need catch codes generated.');
                return;
            }

            // Generate catch codes with progress bar
            $progressBar = $this->output->createProgressBar($fursuitsThatNeedCodes->count());
            $progressBar->setFormat('Generating catch codes: %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s%');
            $progressBar->start();

            $counter = 0;
            foreach ($fursuitsThatNeedCodes as $fursuit) {
                $fursuit->catch_code = $this->generateCatchCode();
                $fursuit->save();
                $counter++;
                $progressBar->advance();
            }

            $progressBar->finish();
            $this->newLine();

            $this->info($counter.' Fursuit Catch Codes created!');
        });
    }

    private function generateCatchCode(): string
    {
        // Random uppercase 5 letter string that does not already exist, loop until it does not exist
        do {
            // NO 0 or O for readability
            $catch_code = (new FursuitCatchCode(Fursuit::class, 'catch_code'))->generate();
        } while (Fursuit::where('catch_code', $catch_code)->exists());

        return $catch_code;
    }
}
