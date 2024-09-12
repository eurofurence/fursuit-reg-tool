<?php

namespace App\Filament\Resources\BadgeResource\Pages;

use App\Filament\Resources\BadgeResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateBadge extends CreateRecord
{
    protected static string $resource = BadgeResource::class;
}
