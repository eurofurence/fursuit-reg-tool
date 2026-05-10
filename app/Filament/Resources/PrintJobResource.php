<?php

namespace App\Filament\Resources;

use App\Domain\Printing\Models\PrintJob;
use App\Enum\PrintJobStatusEnum;
use App\Enum\PrintJobTypeEnum;
use App\Filament\Resources\PrintJobResource\Pages;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PrintJobResource extends Resource
{
    protected static ?string $model = PrintJob::class;

    protected static string|\UnitEnum|null $navigationGroup = 'POS';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-queue-list';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Select::make('printer_id')
                    ->label('Printer')
                    ->relationship('printer', 'name')
                    ->required()
                    ->columnSpanFull(),
                Select::make('type')
                    ->options([
                        PrintJobTypeEnum::Badge->value => 'Badge',
                        PrintJobTypeEnum::Receipt->value => 'Receipt',
                    ])
                    ->required()
                    ->columnSpanFull(),
                Select::make('status')
                    ->options([
                        PrintJobStatusEnum::Pending->value => 'Pending',
                        PrintJobStatusEnum::Queued->value => 'Queued',
                        PrintJobStatusEnum::Printing->value => 'Printing',
                        PrintJobStatusEnum::Printed->value => 'Printed',
                        PrintJobStatusEnum::Failed->value => 'Failed',
                        PrintJobStatusEnum::Retrying->value => 'Retrying',
                    ])
                    ->required()
                    ->columnSpanFull(),
                TextInput::make('priority')
                    ->numeric()
                    ->default(0)
                    ->columnSpanFull(),
                TextInput::make('retry_count')
                    ->numeric()
                    ->default(0)
                    ->columnSpanFull(),
                Textarea::make('error_message')
                    ->columnSpanFull()
                    ->rows(3),
                TextInput::make('qz_job_name')
                    ->maxLength(255)
                    ->columnSpanFull(),
                TextInput::make('last_qz_status')
                    ->maxLength(255)
                    ->columnSpanFull(),
                Textarea::make('last_qz_message')
                    ->columnSpanFull()
                    ->rows(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                TextColumn::make('printer.name')
                    ->label('Printer')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->badge()
                    ->getStateUsing(fn (PrintJob $record): string => $record->type->value)
                    ->colors([
                        'primary' => 'badge',
                        'secondary' => 'receipt',
                    ]),
                TextColumn::make('status')
                    ->badge()
                    ->getStateUsing(fn (PrintJob $record): string => $record->status->value)
                    ->colors([
                        'warning' => 'pending',
                        'info' => 'queued',
                        'primary' => 'printing',
                        'success' => 'printed',
                        'danger' => 'failed',
                        'secondary' => 'retrying',
                    ]),
                TextColumn::make('printable')
                    ->label('Printable')
                    ->getStateUsing(function (PrintJob $record): string {
                        if ($record->printable_type === 'App\\Models\\Badge\\Badge') {
                            return "Badge #{$record->printable?->custom_id}";
                        }

                        return class_basename($record->printable_type)." #{$record->printable_id}";
                    }),
                TextColumn::make('priority')
                    ->sortable()
                    ->badge()
                    ->color(fn (int $state): string => match (true) {
                        $state >= 10 => 'danger',
                        $state >= 5 => 'warning',
                        $state >= 1 => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('retry_count')
                    ->label('Retries')
                    ->badge()
                    ->color(fn (int $state): string => match (true) {
                        $state >= 3 => 'danger',
                        $state >= 1 => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('processingMachine.name')
                    ->label('Machine')
                    ->placeholder('Not assigned'),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('printed_at')
                    ->label('Printed')
                    ->dateTime()
                    ->placeholder('Not printed'),
                TextColumn::make('error_message')
                    ->label('Error')
                    ->limit(50)
                    ->placeholder('None')
                    ->tooltip(function (PrintJob $record): ?string {
                        return $record->error_message;
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        PrintJobStatusEnum::Pending->value => 'Pending',
                        PrintJobStatusEnum::Queued->value => 'Queued',
                        PrintJobStatusEnum::Printing->value => 'Printing',
                        PrintJobStatusEnum::Printed->value => 'Printed',
                        PrintJobStatusEnum::Failed->value => 'Failed',
                        PrintJobStatusEnum::Retrying->value => 'Retrying',
                    ]),
                SelectFilter::make('type')
                    ->options([
                        PrintJobTypeEnum::Badge->value => 'Badge',
                        PrintJobTypeEnum::Receipt->value => 'Receipt',
                    ]),
                SelectFilter::make('printer')
                    ->relationship('printer', 'name'),
                Filter::make('printable_id')
                    ->form([
                        TextInput::make('value')
                            ->label('Printable ID')
                            ->numeric(),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            $data['value'],
                            fn (Builder $query, $value): Builder => $query->where('printable_id', $value),
                        );
                    })
                    ->indicateUsing(function (array $data): ?string {
                        if (! $data['value']) {
                            return null;
                        }

                        return 'Printable ID: '.$data['value'];
                    }),
                Filter::make('printable_type')
                    ->form([
                        TextInput::make('value')
                            ->label('Printable Type'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            $data['value'],
                            fn (Builder $query, $value): Builder => $query->where('printable_type', $value),
                        );
                    })
                    ->indicateUsing(function (array $data): ?string {
                        if (! $data['value']) {
                            return null;
                        }

                        return 'Type: '.class_basename($data['value']);
                    }),
            ])
            ->actions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('retry')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (PrintJob $record): bool => $record->canRetry())
                    ->action(function (PrintJob $record) {
                        $retryJob = $record->createRetryJob(reassignPrinter: true);
                        Notification::make()
                            ->success()
                            ->title("Created retry job #{$retryJob->id}")
                            ->send();
                    })
                    ->requiresConfirmation(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('id', 'desc')
            ->poll('5s');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPrintJobs::route('/'),
            'create' => Pages\CreatePrintJob::route('/create'),
            'view' => Pages\ViewPrintJob::route('/{record}'),
            'edit' => Pages\EditPrintJob::route('/{record}/edit'),
        ];
    }

    // Custom method to handle printer filtering from URL
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        // Check if we have a printer filter from URL
        if (request()->has('printer')) {
            $query->where('printer_id', request('printer'));
        }

        return $query;
    }
}
