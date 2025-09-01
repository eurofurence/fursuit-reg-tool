<?php

namespace App\Filament\Resources\SpecialCodeResource\Pages;


use App\Filament\Resources\SpecialCodeResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageSpecialCodes extends ManageRecords
{
    protected static string $resource = SpecialCodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
