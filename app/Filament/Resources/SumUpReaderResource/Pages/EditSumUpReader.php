<?php

namespace App\Filament\Resources\SumUpReaderResource\Pages;

use App\Filament\Resources\SumUpReaderResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSumUpReader extends EditRecord
{
    protected static string $resource = SumUpReaderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
