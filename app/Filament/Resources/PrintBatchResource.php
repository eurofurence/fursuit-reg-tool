<?php

namespace App\Filament\Resources;

use App\Domain\Printing\Models\PrintBatch;
use App\Enum\PrintBatchStatusEnum;
use App\Enum\PrintJobStatusEnum;
use App\Filament\Resources\PrintBatchResource\Pages;
use App\Filament\Resources\PrintBatchResource\RelationManagers;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Batch oversight for staff who are not standing at the printer.
 *
 * Everything here is read-only apart from the three run controls and the manual
 * verification. A batch is immutable once built, so there is no create or edit
 * form: batches come from the "Build print batch" bulk action on badges, which
 * is the only path that can freeze the sequence and lock the badges together.
 */
class PrintBatchResource extends Resource
{
    protected static ?string $model = PrintBatch::class;

    protected static ?string $navigationGroup = 'POS';

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationLabel = 'Print Batches';

    protected static ?int $navigationSort = 2;

    /**
     * Cards that printed but nobody has confirmed came out right. This is the
     * number staff act on, so it is the one worth carrying in the sidebar.
     */
    public static function getNavigationBadge(): ?string
    {
        $unverified = PrintBatch::query()
            ->whereHas('printJobs', fn (Builder $query) => $query->where('status', PrintJobStatusEnum::Printed)
                ->whereNull('verified_print_at'))
            ->count();

        return $unverified > 0 ? (string) $unverified : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /**
     * Batches are immutable, so there is nothing to fill in. The form exists
     * only because Filament requires one on a resource.
     */
    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Placeholder::make('immutable')
                ->label('')
                ->content('Batches are immutable. Build one from the badge list instead.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (PrintBatchStatusEnum $state): string => $state->label())
                    ->color(fn (PrintBatchStatusEnum $state): string => static::statusColor($state)),

                Tables\Columns\TextColumn::make('printer.name')
                    ->label('Printer')
                    ->placeholder('Unassigned')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('event.name')
                    ->label('Event')
                    ->placeholder('None')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('progress')
                    ->label('Progress')
                    ->getStateUsing(fn (PrintBatch $record): string => "{$record->printed_count} / {$record->total_jobs}")
                    ->description(fn (PrintBatch $record): string => "{$record->verified_count} verified, {$record->failed_count} failed")
                    ->color(fn (PrintBatch $record): string => match (true) {
                        $record->failed_count > 0 => 'danger',
                        $record->printed_count >= $record->total_jobs && $record->total_jobs > 0 => 'success',
                        default => 'info',
                    })
                    ->badge(),

                Tables\Columns\TextColumn::make('unverified')
                    ->label('Needs check')
                    ->getStateUsing(fn (PrintBatch $record): int => $record->printed_count - $record->verified_count)
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('pause_reason')
                    ->label('Reason')
                    ->limit(40)
                    ->placeholder('None')
                    ->tooltip(fn (PrintBatch $record): ?string => $record->pause_reason),

                Tables\Columns\TextColumn::make('createdBy.name')
                    ->label('Built by')
                    ->placeholder('System')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('started_at')
                    ->label('Started')
                    ->dateTime('M j, H:i')
                    ->placeholder('Not started')
                    ->sortable(),

                Tables\Columns\TextColumn::make('completed_at')
                    ->label('Completed')
                    ->dateTime('M j, H:i')
                    ->placeholder('Not finished')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(collect(PrintBatchStatusEnum::cases())
                        ->mapWithKeys(fn (PrintBatchStatusEnum $case) => [$case->value => $case->label()])
                        ->all())
                    ->multiple(),

                Tables\Filters\SelectFilter::make('printer')
                    ->relationship('printer', 'name'),

                Tables\Filters\Filter::make('needs_verification')
                    ->label('Has unverified cards')
                    // The queue moved on but nothing has vouched for the card.
                    // These are the batches somebody has to walk over and check.
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'printJobs',
                        fn (Builder $jobs) => $jobs->where('status', PrintJobStatusEnum::Printed)
                            ->whereNull('verified_print_at')
                    ))
                    ->toggle(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),

                Tables\Actions\Action::make('pause')
                    ->icon('heroicon-o-pause')
                    ->color('warning')
                    ->visible(fn (PrintBatch $record): bool => $record->status === PrintBatchStatusEnum::Printing)
                    ->form([
                        Forms\Components\TextInput::make('reason')
                            ->label('Why is it being paused?')
                            ->required()
                            ->maxLength(1000)
                            ->helperText('Shown to whoever is standing at the printer.'),
                    ])
                    ->action(function (PrintBatch $record, array $data) {
                        $record->pause($data['reason']);

                        Notification::make()->success()->title('Batch paused')->send();
                    }),

                Tables\Actions\Action::make('resume')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->visible(fn (PrintBatch $record): bool => $record->status === PrintBatchStatusEnum::Paused)
                    ->requiresConfirmation()
                    ->modalDescription('Only resume once the fault at the printer has actually been dealt with.')
                    ->action(function (PrintBatch $record) {
                        $record->resume();

                        Notification::make()->success()->title('Batch resumed')->send();
                    }),

                Tables\Actions\Action::make('cancel')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (PrintBatch $record): bool => ! $record->status->isTerminal())
                    ->requiresConfirmation()
                    ->modalHeading('Cancel this batch')
                    ->modalDescription('Cards already printed stay printed. Everything still queued is cancelled, and attendees whose card never printed get their badge back to edit.')
                    ->form([
                        Forms\Components\TextInput::make('reason')
                            ->label('Reason')
                            ->maxLength(1000)
                            ->default('Cancelled from the admin panel'),
                    ])
                    ->action(function (PrintBatch $record, array $data) {
                        $cancelled = $record->cancel($data['reason'] ?: 'Cancelled from the admin panel');

                        Notification::make()
                            ->status($cancelled ? 'success' : 'danger')
                            ->title($cancelled ? 'Batch cancelled' : "Cannot cancel a batch that is {$record->status->label()}")
                            ->send();
                    }),
            ])
            ->defaultSort('id', 'desc')
            ->poll('10s');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Batch')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('name'),
                    Infolists\Components\TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (PrintBatchStatusEnum $state): string => $state->label())
                        ->color(fn (PrintBatchStatusEnum $state): string => static::statusColor($state)),
                    Infolists\Components\TextEntry::make('printer.name')
                        ->label('Printer')
                        ->placeholder('Unassigned'),
                    Infolists\Components\TextEntry::make('event.name')
                        ->label('Event')
                        ->placeholder('None'),
                    Infolists\Components\TextEntry::make('createdBy.name')
                        ->label('Built by')
                        ->placeholder('System'),
                    Infolists\Components\TextEntry::make('pause_reason')
                        ->label('Pause reason')
                        ->placeholder('None'),
                ]),

            Infolists\Components\Section::make('Progress')
                ->columns(4)
                ->schema([
                    Infolists\Components\TextEntry::make('total_jobs')->label('Cards'),
                    Infolists\Components\TextEntry::make('printed_count')->label('Printed')->color('success'),
                    Infolists\Components\TextEntry::make('verified_count')->label('Verified')->color('success'),
                    Infolists\Components\TextEntry::make('failed_count')->label('Failed')->color('danger'),
                ]),

            Infolists\Components\Section::make('Timing')
                ->columns(3)
                ->collapsed()
                ->schema([
                    Infolists\Components\TextEntry::make('created_at')->dateTime(),
                    Infolists\Components\TextEntry::make('started_at')->dateTime()->placeholder('Not started'),
                    Infolists\Components\TextEntry::make('completed_at')->dateTime()->placeholder('Not finished'),
                ]),
        ]);
    }

    public static function statusColor(PrintBatchStatusEnum $status): string
    {
        return match ($status) {
            PrintBatchStatusEnum::Draft => 'gray',
            PrintBatchStatusEnum::Ready => 'info',
            PrintBatchStatusEnum::Printing => 'primary',
            PrintBatchStatusEnum::Paused => 'warning',
            PrintBatchStatusEnum::Completed => 'success',
            PrintBatchStatusEnum::Cancelled => 'danger',
        };
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\PrintJobsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPrintBatches::route('/'),
            'view' => Pages\ViewPrintBatch::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
