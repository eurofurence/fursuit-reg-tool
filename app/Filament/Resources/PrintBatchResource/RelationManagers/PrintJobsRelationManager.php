<?php

namespace App\Filament\Resources\PrintBatchResource\RelationManagers;

use App\Domain\Printing\Models\PrintJob;
use App\Enum\PrintJobStatusEnum;
use App\Enum\PrintVerificationSourceEnum;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * The cards in a batch, in the order they print.
 *
 * The column that matters most is Verified. A job reaching Printed only means
 * something claimed it finished; whether a correct card physically came out is
 * a separate question, and the ones nobody has answered are exactly the ones
 * staff need to walk over and check.
 */
class PrintJobsRelationManager extends RelationManager
{
    protected static string $relationship = 'printJobs';

    protected static ?string $title = 'Cards';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('sequence')
            ->columns([
                Tables\Columns\TextColumn::make('sequence')
                    ->label('#')
                    ->sortable(),

                Tables\Columns\TextColumn::make('printable.custom_id')
                    ->label('Badge')
                    ->searchable()
                    ->placeholder('Deleted'),

                Tables\Columns\TextColumn::make('printable.fursuit.name')
                    ->label('Fursuit')
                    ->searchable()
                    ->placeholder('Deleted'),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (PrintJobStatusEnum $state) => $state->label())
                    ->color(fn (PrintJobStatusEnum $state) => match ($state) {
                        PrintJobStatusEnum::Printed => 'success',
                        PrintJobStatusEnum::Failed => 'danger',
                        PrintJobStatusEnum::Printing, PrintJobStatusEnum::Queued => 'primary',
                        PrintJobStatusEnum::Retrying => 'warning',
                        PrintJobStatusEnum::Cancelled => 'gray',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('completion_source')
                    ->label('Finished by')
                    ->placeholder('Not finished')
                    ->formatStateUsing(fn ($state) => $state?->label()),

                Tables\Columns\IconColumn::make('verified_print_at')
                    ->label('Verified')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-badge')
                    ->falseIcon('heroicon-o-question-mark-circle')
                    ->trueColor('success')
                    ->falseColor('warning')
                    ->getStateUsing(fn (PrintJob $record) => $record->verified_print_at !== null)
                    ->tooltip(fn (PrintJob $record) => $record->verified_print_at
                        ? $record->verification_source?->label()
                        : 'Nobody has confirmed this card came out'),

                Tables\Columns\TextColumn::make('attempt_count')
                    ->label('Tries')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('error_message')
                    ->label('Error')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('None'),
            ])
            ->filters([
                Tables\Filters\Filter::make('unverified')
                    ->label('Printed but unverified')
                    ->query(fn ($query) => $query->unverified()),

                Tables\Filters\SelectFilter::make('status')
                    ->options(collect(PrintJobStatusEnum::cases())
                        ->mapWithKeys(fn (PrintJobStatusEnum $case) => [$case->value => $case->label()])
                        ->all()),
            ])
            ->actions([
                Tables\Actions\Action::make('verify')
                    ->label('Mark verified')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Confirm this card')
                    ->modalDescription('Only do this with the printed card in front of you. This records that a human checked it.')
                    // Only offered for cards that printed and that nobody has
                    // vouched for yet.
                    ->visible(fn (PrintJob $record) => $record->status === PrintJobStatusEnum::Printed
                        && $record->verified_print_at === null)
                    ->action(function (PrintJob $record) {
                        $record->markVerified(PrintVerificationSourceEnum::Operator, auth()->user());

                        Notification::make()
                            ->title('Card verified')
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([]);
    }

    /**
     * Nothing here creates or deletes a job. A batch is immutable, so its
     * contents can only be changed by cancelling the whole run.
     */
    public function isReadOnly(): bool
    {
        return false;
    }
}
