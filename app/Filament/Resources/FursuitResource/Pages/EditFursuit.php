<?php

namespace App\Filament\Resources\FursuitResource\Pages;

use App\Filament\Resources\FursuitResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditFursuit extends EditRecord
{
    protected static string $resource = FursuitResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
