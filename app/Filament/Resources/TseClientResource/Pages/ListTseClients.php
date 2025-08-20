<?php

namespace App\Filament\Resources\TseClientResource\Pages;

use App\Domain\Checkout\Models\TseClient;
use App\Filament\Resources\TseClientResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Str;

class ListTseClients extends ListRecords
{
    protected static string $resource = TseClientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('createnew')
                ->label('Create TSE Client')
                ->icon('heroicon-o-plus-circle')
                ->action(function () {
                    $uuid = Str::uuid();
                    TseClient::create([
                        'remote_id' => $uuid,
                        'serial_number' => $uuid,
                        'state' => 'REGISTERED',
                    ]);
                }),
        ];
    }
}
