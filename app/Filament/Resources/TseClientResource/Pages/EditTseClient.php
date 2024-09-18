<?php

namespace App\Filament\Resources\TseClientResource\Pages;

use App\Filament\Resources\TseClientResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTseClient extends EditRecord
{
    protected static string $resource = TseClientResource::class;

    protected function getHeaderActions(): array
    {
        return [
        ];
    }
}
