<?php

namespace App\Filament\Resources\FursuitResource\Pages;

use App\Filament\Resources\FursuitResource;
use App\Models\Fursuit\Fursuit;
use App\Models\Fursuit\States\Approved;
use App\Models\Fursuit\States\Rejected;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Set;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\DB;

class ViewFursuit extends ViewRecord
{
    protected static string $resource = FursuitResource::class;

    protected function getHeaderActions(): array
    {
        $errorOptions = [
            'The submission shows a fursuit that is not owned by your or was created without the owners permission.',
            'The submission shows a human. We can only accept badges created for fursuits.',
            'The submission is explicit and does not follow our guidelines.',
            'The submission is of low quality and does not meet our guidelines.',
            'The submission is a not a photo. We only accept photos, we do not accept illustrations or other digital art as fursuit images.',
            'The submission is AI generated and does not show a real fursuit.',
            'The name of the fursuit is not appropriate.',
            'The species of the fursuit is not appropriate.',
        ];
        return [
            //Actions\EditAction::make(),
            Actions\Action::make('Approve')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn(Fursuit $record) => $record->status->canTransitionTo(Approved::class, auth()->user()))
                ->action(function (Fursuit $record) {
                    if($record === null) {
                        return;
                    }
                    $record->status->transitionTo(Approved::class, auth()->user());
                    $nextFursuit = Fursuit::where('status', 'pending')->first();
                    if ($nextFursuit) {
                        return redirect()->route('filament.admin.resources.fursuits.view', $nextFursuit);
                    }
                    return redirect()->route('filament.admin.resources.fursuits.index');
                }),
            Actions\Action::make('Reject')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->form([
                    Select::make('reason')
                        ->live()
                        ->afterStateUpdated(fn(Set $set, ?string $state) => $set('custom_reason',
                            $errorOptions[$state]))
                        ->options($errorOptions),
                    Textarea::make('custom_reason')
                        ->label('Reason Sent to the User!')
                        ->required(),
                ])
                ->visible(fn(Fursuit $record) => $record->status->canTransitionTo(Rejected::class, auth()->user(), ""))
                ->action(function (Fursuit $record, array $data) {
                    $record->status->transitionTo(Rejected::class, auth()->user(), $data['custom_reason']);
                    $record->save();
                    $nextFursuit = Fursuit::where('status', 'pending')->first();
                    if ($nextFursuit) {
                        return redirect()->route('filament.admin.resources.fursuits.view', $nextFursuit);
                    }
                    return redirect()->route('filament.admin.resources.fursuits.index');
                }),
            // NEXT FURSUIT
            Actions\Action::make('Next Fursuit')
                ->icon('heroicon-o-arrow-right')
                ->color('primary')
                ->action(function (Fursuit $record) {
                    $nextFursuit = Fursuit::where('status', 'pending')
                        ->where('id', '!=', $record->id)
                        ->first();
                    if ($nextFursuit) {
                        return redirect()->route('filament.admin.resources.fursuits.view', $nextFursuit);
                    }
                    return redirect()->route('filament.admin.resources.fursuits.index');
                }),
        ];
    }
}
