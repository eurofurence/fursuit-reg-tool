<?php

namespace App\Filament\Resources\SumUpReaderResource\Pages;

use App\Filament\Resources\SumUpReaderResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSumUpReaders extends ListRecords
{
    protected static string $resource = SumUpReaderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
