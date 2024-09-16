<?php

namespace App\Filament\Resources\SumUpReadersResource\Pages;

use App\Filament\Resources\SumUpReaderResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageSumUpReader extends ManageRecords
{
    protected static string $resource = SumUpReaderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
