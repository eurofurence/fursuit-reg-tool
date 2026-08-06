<?php

namespace App\Filament\Resources\PrintBatchResource\Pages;

use App\Filament\Resources\PrintBatchResource;
use Filament\Resources\Pages\ListRecords;

class ListPrintBatches extends ListRecords
{
    protected static string $resource = PrintBatchResource::class;

    /**
     * No create action: a batch can only come from PrintBatch::build(), which
     * needs the badges it will contain.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
