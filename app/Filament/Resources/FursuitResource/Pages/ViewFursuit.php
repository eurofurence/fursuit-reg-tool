<?php

namespace App\Filament\Resources\FursuitResource\Pages;

use App\Filament\Resources\FursuitResource;
use App\Models\Fursuit\Fursuit;
use App\Models\Fursuit\States\Approved;
use App\Models\Fursuit\States\Rejected;
use App\Models\Fursuit\States\Transitions\RejectedToApproved;
use App\Notifications\FursuitApprovedNotification;
use App\Notifications\FursuitRejectedNotification;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Log;

class ViewFursuit extends ViewRecord
{
    protected static string $resource = FursuitResource::class;

    public $defaultAction = 'Claim';

    protected function getHeaderActions(): array
    {
        $errorOptions = [
            'The submission shows a human. We can only accept badges created for fursuits.',
            'The submission is explicit and does not follow our guidelines.',
            'The submission is of low quality and does not meet our guidelines.',
            'The submission is a not a photo. We only accept photos, we do not accept illustrations or other digital art as fursuit images.',
            'The submission shows an animal. We do not allow images of real animals, only fursuits.',
            'The submission is AI generated and does not show a real fursuit.',
            'The name of the fursuit is not appropriate.',
            'The species of the fursuit is not appropriate.',
        ];

        return [
            Actions\Action::make('Claim')
                ->visible(fn (Fursuit $record) => $record->status->canTransitionTo(Approved::$name, auth()->user()) && ! $record->isClaimedBySelf(auth()->user()))
                ->color('primary')
                ->action(function (Fursuit $record) {
                    if ($record->isClaimed() && $record->isClaimedBySelf(auth()->user()) === false) {
                        return $this->toNextFursuit($record);
                    }
                    $record->claim(auth()->user());
                    $record->refresh();
                }),
            // Unclaim if self
            Actions\Action::make('Unclaim')
                ->visible(fn (Fursuit $record) => $record->status->canTransitionTo(Approved::$name, auth()->user()) && $record->isClaimedBySelf(auth()->user()))
                ->color('danger')
                ->action(function (Fursuit $record) {
                    $record->unclaim(auth()->user());
                    $record->refresh();
                }),
            Actions\Action::make('Approve')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (Fursuit $record) => $record->status->canTransitionTo(Approved::class, auth()->user()) && $record->isClaimedBySelf(auth()->user()))
                ->action(function (Fursuit $record) {
                    if ($record === null) {
                        return;
                    }
                    // Check Claim
                    if ($record->isClaimed() === false) {
                        Log::error('Fursuit is not claimed, but user tried to approve it.', ['fursuit' => $record]);

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
                        ->afterStateUpdated(fn (Set $set, ?string $state) => $set('custom_reason',
                            $errorOptions[$state]))
                        ->options($errorOptions),
                    Textarea::make('custom_reason')
                        ->label('Reason Sent to the User!')
                        ->required(),
                ])
                ->visible(fn (Fursuit $record) => $record->status->canTransitionTo(Rejected::class, auth()->user(), '') && $record->isClaimedBySelf(auth()->user()))
                ->action(function (Fursuit $record, array $data) {
                    // Check Claim
                    if ($record->isClaimed() === false) {
                        Log::error('Fursuit is not claimed, but user tried to reject it.', ['fursuit' => $record]);

                        return;
                    }
                    $record->status->transitionTo(Rejected::class, auth()->user(), $data['custom_reason']);
                    $record->save();
                    $nextFursuit = Fursuit::where('status', 'pending')->first();
                    if ($nextFursuit) {
                        return redirect()->route('filament.admin.resources.fursuits.view', $nextFursuit);
                    }

                    return redirect()->route('filament.admin.resources.fursuits.index');
                }),
            Actions\Action::make('Approve Rejected')
                ->label('Approve (Rejected)')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (Fursuit $record) => $record->status instanceof Rejected)
                ->requiresConfirmation()
                ->modalHeading('Approve Rejected Fursuit')
                ->modalDescription('This will send an apology email to the user and approve the fursuit.')
                ->modalSubmitActionLabel('Yes, approve it')
                ->action(function (Fursuit $record) {
                    $record->status->transitionTo(Approved::class, auth()->user());
                    
                    Notification::make()
                        ->title('Rejected fursuit approved successfully')
                        ->success()
                        ->send();
                }),
            Actions\Action::make('Send Notification')
                ->label('Send Notification')
                ->icon('heroicon-o-envelope')
                ->color('info')
                ->form([
                    Select::make('notification_type')
                        ->label('Notification Type')
                        ->options([
                            'approved' => 'Approval Notification',
                            'rejected' => 'Rejection Notification',
                        ])
                        ->required(),
                    Textarea::make('rejection_reason')
                        ->label('Rejection Reason (Required for Rejection)')
                        ->visible(fn ($get) => $get('notification_type') === 'rejected')
                        ->required(fn ($get) => $get('notification_type') === 'rejected'),
                ])
                ->action(function (Fursuit $record, array $data) {
                    if ($data['notification_type'] === 'approved') {
                        $record->user->notify(new FursuitApprovedNotification($record));
                        $message = 'Approval notification sent successfully';
                    } else {
                        $reason = $data['rejection_reason'] ?? 'No reason provided';
                        $record->user->notify(new FursuitRejectedNotification($record, $reason));
                        $message = 'Rejection notification sent successfully';
                    }
                    
                    Notification::make()
                        ->title($message)
                        ->success()
                        ->send();
                }),
            // NEXT FURSUIT
            Actions\Action::make('Next Fursuit')
                ->icon('heroicon-o-arrow-right')
                ->color('primary')
                ->action(function (Fursuit $record) {
                    return $this->toNextFursuit($record);
                }),
        ];
    }

    private function toNextFursuit(Fursuit $record)
    {
        // Try three times to find a next unclaimed fursuit and then exit to index
        $tries = 0;
        $maxTries = 3;
        $excludedIds = [$record->id];
        do {
            $nextFursuit = Fursuit::where('status', 'pending')
                ->whereNotIn('id', $excludedIds)
                ->first();
            if ($nextFursuit) {
                $excludedIds[] = $nextFursuit->id;
            }
            $tries++;
        } while ($nextFursuit && $nextFursuit->isClaimed() && $tries < $maxTries);

        if ($nextFursuit) {
            return redirect()->route('filament.admin.resources.fursuits.view', $nextFursuit);
        }

        return redirect()->route('filament.admin.resources.fursuits.index');
    }
}
