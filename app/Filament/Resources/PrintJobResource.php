<?php

namespace App\Filament\Resources;

use App\Domain\Printing\Models\PrintJob;
use App\Enum\PrintJobStatusEnum;
use App\Enum\PrintJobTypeEnum;
use App\Filament\Resources\PrintJobResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

class PrintJobResource extends Resource
{
    protected static ?string $model = PrintJob::class;

    protected static ?string $navigationGroup = 'POS';

    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('printer_id')
                    ->label('Printer')
                    ->relationship('printer', 'name')
                    ->required()
                    ->columnSpanFull(),
                Forms\Components\Select::make('type')
                    ->options([
                        PrintJobTypeEnum::Badge->value => 'Badge',
                        PrintJobTypeEnum::Receipt->value => 'Receipt',
                    ])
                    ->required()
                    ->columnSpanFull(),
                Forms\Components\Select::make('status')
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
                Forms\Components\TextInput::make('priority')
                    ->numeric()
                    ->default(0)
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('retry_count')
                    ->numeric()
                    ->default(0)
                    ->columnSpanFull(),
                Forms\Components\Textarea::make('error_message')
                    ->columnSpanFull()
                    ->rows(3),
                Forms\Components\TextInput::make('qz_job_name')
                    ->maxLength(255)
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('last_qz_status')
                    ->maxLength(255)
                    ->columnSpanFull(),
                Forms\Components\Textarea::make('last_qz_message')
                    ->columnSpanFull()
                    ->rows(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                Tables\Columns\TextColumn::make('printer.name')
                    ->label('Printer')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\BadgeColumn::make('type')
                    ->getStateUsing(fn (PrintJob $record): string => $record->type->value)
                    ->colors([
                        'primary' => 'badge',
                        'secondary' => 'receipt',
                    ]),
                Tables\Columns\BadgeColumn::make('status')
                    ->getStateUsing(fn (PrintJob $record): string => $record->status->value)
                    ->colors([
                        'warning' => 'pending',
                        'info' => 'queued',
                        'primary' => 'printing',
                        'success' => 'printed',
                        'danger' => 'failed',
                        'secondary' => 'retrying',
                    ]),
                Tables\Columns\TextColumn::make('printable')
                    ->label('Printable')
                    ->getStateUsing(function (PrintJob $record): string {
                        if ($record->printable_type === 'App\\Models\\Badge\\Badge') {
                            return "Badge #{$record->printable?->custom_id}";
                        }
                        return class_basename($record->printable_type) . " #{$record->printable_id}";
                    }),
                Tables\Columns\TextColumn::make('priority')
                    ->sortable()
                    ->badge()
                    ->color(fn (int $state): string => match (true) {
                        $state >= 10 => 'danger',
                        $state >= 5 => 'warning',
                        $state >= 1 => 'info',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('retry_count')
                    ->label('Retries')
                    ->badge()
                    ->color(fn (int $state): string => match (true) {
                        $state >= 3 => 'danger',
                        $state >= 1 => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('processingMachine.name')
                    ->label('Machine')
                    ->placeholder('Not assigned'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('printed_at')
                    ->label('Printed')
                    ->dateTime()
                    ->placeholder('Not printed'),
                Tables\Columns\TextColumn::make('error_message')
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
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('retry')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (PrintJob $record): bool => $record->canRetry())
                    ->action(function (PrintJob $record) {
                        $retryJob = $record->createRetryJob(reassignPrinter: true);
                        \Filament\Notifications\Notification::make()
                            ->success()
                            ->title("Created retry job #{$retryJob->id}")
                            ->send();
                    })
                    ->requiresConfirmation(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
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