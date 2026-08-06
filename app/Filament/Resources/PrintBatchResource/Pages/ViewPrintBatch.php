<?php

namespace App\Filament\Resources\PrintBatchResource\Pages;

use App\Filament\Resources\PrintBatchResource;
use Filament\Resources\Pages\ViewRecord;

class ViewPrintBatch extends ViewRecord
{
    protected static string $resource = PrintBatchResource::class;

    /**
     * Run controls live on the table row rather than here, so staff can pause a
     * batch from the list without opening it. A batch is immutable once built,
     * so there is nothing to edit on this page either.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
