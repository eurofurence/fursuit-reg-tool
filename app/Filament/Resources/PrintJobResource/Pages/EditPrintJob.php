<?php

namespace App\Filament\Resources\PrintJobResource\Pages;

use App\Filament\Resources\PrintJobResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPrintJob extends EditRecord
{
    protected static string $resource = PrintJobResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
