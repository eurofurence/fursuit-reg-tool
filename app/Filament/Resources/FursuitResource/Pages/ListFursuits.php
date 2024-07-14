<?php

namespace App\Filament\Resources\FursuitResource\Pages;

use App\Filament\Resources\FursuitResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListFursuits extends ListRecords
{
    protected static string $resource = FursuitResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
