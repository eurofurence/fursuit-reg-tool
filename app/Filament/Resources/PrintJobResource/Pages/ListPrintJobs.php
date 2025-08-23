<?php

namespace App\Filament\Resources\PrintJobResource\Pages;

use App\Filament\Resources\PrintJobResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPrintJobs extends ListRecords
{
    protected static string $resource = PrintJobResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    public function getTitle(): string
    {
        // Check if we're filtering by printer
        if (request()->has('printer')) {
            $printerName = \App\Domain\Printing\Models\Printer::find(request('printer'))?->name ?? 'Unknown';
            return "Print Jobs - {$printerName}";
        }
        
        return 'Print Jobs';
    }
}