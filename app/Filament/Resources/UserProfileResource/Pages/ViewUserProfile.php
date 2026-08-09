<?php

namespace App\Filament\Resources\UserProfileResource\Pages;

use App\Filament\Resources\UserProfileResource;
use App\Models\UserProfile\States\Approved;
use App\Models\UserProfile\States\Pending;
use App\Models\UserProfile\States\Rejected;
use App\Models\UserProfile\UserProfile;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewUserProfile extends ViewRecord
{
    protected static string $resource = UserProfileResource::class;

    // Auto-claim the profile when a reviewer opens it
    public $defaultAction = 'Claim';

    protected function getHeaderActions(): array
    {
        $errorOptions = [
            'The profile contains offensive content or violates the rules.',
            'The profile contains illegal content or links to illegal content.',
            'The profile contains dangerous content or links to dangerous content.',
            'The profile contains spam or advertising.',
            'The profile contains personal information of other people.',
            'Other (explain in the rejection reason)',
        ];

        return [
            Actions\Action::make('Claim')
                ->visible(fn (UserProfile $record) => $record->status->canTransitionTo(Approved::class, auth()->user()) && ! $record->isClaimedBySelf(auth()->user()))
                ->color('primary')
                ->action(function (UserProfile $record) {
                    if ($record->isClaimed() && ! $record->isClaimedBySelf(auth()->user())) {
                        return $this->toNextProfile($record);
                    }
                    $record->claim(auth()->user());
                }),
            Actions\Action::make('Unclaim')
                ->visible(fn (UserProfile $record) => $record->status->canTransitionTo(Approved::class, auth()->user()) && $record->isClaimedBySelf(auth()->user()))
                ->color('danger')
                ->action(fn (UserProfile $record) => $record->unclaim()),
            Actions\Action::make('Approve')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription('This publishes the profile description and all of its links.')
                ->visible(fn (UserProfile $record) => $record->status->canTransitionTo(Approved::class, auth()->user()) && $record->isClaimedBySelf(auth()->user()))
                ->action(function (UserProfile $record) {
                    if (! $record->isClaimedBySelf(auth()->user())) {
                        return $this->claimLost();
                    }

                    $record->status->transitionTo(Approved::class, auth()->user());

                    Notification::make()
                        ->title('Profile approved')
                        ->success()
                        ->send();

                    return $this->toNextProfile($record);
                }),
            Actions\Action::make('Reject')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('The description and links stay hidden from other attendees.')
                ->form([
                    Select::make('premade_reason')
                        ->live()
                        ->afterStateUpdated(fn (Set $set, ?string $state) => $set('reason',
                            $errorOptions[$state]))
                        ->options($errorOptions),
                    Textarea::make('reason')
                        ->label('Reason shown to the user')
                        ->required()
                        ->maxLength(255),
                ])
                // bleh
                ->visible(fn (UserProfile $record) => $record->status->canTransitionTo(Rejected::class, auth()->user(), '') && $record->isClaimedBySelf(auth()->user()))
                ->action(function (UserProfile $record, array $data) {
                    if (! $record->isClaimedBySelf(auth()->user())) {
                        return $this->claimLost();
                    }

                    $record->status->transitionTo(Rejected::class, auth()->user(), $data['reason']);

                    Notification::make()
                        ->title('Profile rejected')
                        ->success()
                        ->send();

                    return $this->toNextProfile($record);
                }),
            Actions\Action::make('Reopen')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->requiresConfirmation()
                ->visible(fn (UserProfile $record) => $record->status->canTransitionTo(Pending::class, auth()->user()))
                ->action(function (UserProfile $record) {
                    $record->status->transitionTo(Pending::class, auth()->user());

                    Notification::make()
                        ->title('Profile moved back to pending')
                        ->success()
                        ->send();
                }),
            Actions\Action::make('Next Profile')
                ->icon('heroicon-o-arrow-right')
                ->color('primary')
                ->action(fn (UserProfile $record) => $this->toNextProfile($record)),
        ];
    }

    private function claimLost()
    {
        Notification::make()
            ->title('Your claim on this profile has expired')
            ->body('Claim it again before approving or rejecting.')
            ->warning()
            ->send();
    }

    private function toNextProfile(UserProfile $record)
    {
        $tries = 0;
        $maxTries = 3;
        $excludedIds = [$record->id];
        do {
            $nextProfile = UserProfileResource::pendingQuery()
                ->whereNotIn('id', $excludedIds)
                ->first();
            if ($nextProfile) {
                $excludedIds[] = $nextProfile->id;
            }
            $tries++;
        } while ($nextProfile && $nextProfile->isClaimed() && $tries < $maxTries);

        if ($nextProfile) {
            return redirect()->route('filament.admin.resources.user-profiles.view', $nextProfile);
        }

        return redirect()->route('filament.admin.resources.user-profiles.index');
    }
}
