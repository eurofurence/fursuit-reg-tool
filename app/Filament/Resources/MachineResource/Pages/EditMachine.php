<?php

namespace App\Filament\Resources\MachineResource\Pages;

use App\Filament\Resources\MachineResource;
use App\Models\Machine;
use Filament\Actions;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\URL;

class EditMachine extends EditRecord
{
    protected static string $resource = MachineResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('Login Link')
                ->modal()
                ->infolist([
                    TextEntry::make('Login Link')
                        ->copyable()
                        ->getStateUsing(fn (Machine $record) => URL::signedRoute('pos.auth.machine.login', [
                            'machine_id' => $record->id,
                        ])),
                ]),
        ];
    }
}
