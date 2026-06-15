<?php

namespace App\Filament\Resources;

use App\Domain\Printing\Models\Printer;
use App\Filament\Resources\PrinterResource\Pages;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\CheckboxColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PrinterResource extends Resource
{
    protected static ?string $model = Printer::class;

    protected static string|\UnitEnum|null $navigationGroup = 'POS';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-printer';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextInput::make('name')
                    ->required()
                    ->columnSpanFull()
                    ->maxLength(255),
                Select::make('type')
                    ->options([
                        'receipt' => 'Receipt',
                        'badge' => 'Badge',
                    ])
                    ->required()
                    ->columnSpanFull(),
                Select::make('machine_id')
                    ->label('Machine')
                    ->relationship('machine', 'name')
                    ->required()
                    ->columnSpanFull(),
                Select::make('default_paper_size')
                    ->options(fn (Printer $record) => collect($record->paper_sizes)->pluck('name', 'name'))
                    ->columnSpanFull(),
                // Json paper_sizes only view
                Textarea::make('paper_sizes')
                    ->disabled()
                    ->formatStateUsing(function ($state) {
                        return json_encode($state, JSON_PRETTY_PRINT);
                    })
                    ->default('{}')
                    ->rows(10)
                    ->columnSpanFull(),
                Checkbox::make('is_active')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('type'),
                TextColumn::make('machine.name')->label('Machine'),
                TextColumn::make('status')
                    ->badge()
                    ->getStateUsing(fn (Printer $record): string => $record->status->value ?? 'unknown')
                    ->colors([
                        'success' => 'idle',
                        'warning' => 'working',
                        'danger' => 'paused',
                        'secondary' => 'offline',
                        'info' => 'processing',
                        'gray' => 'unknown',
                    ]),
                TextColumn::make('pending_jobs')
                    ->label('Pending Jobs')
                    ->getStateUsing(fn (Printer $record): int => $record->printJobs()->where('status', 'pending')->count())
                    ->url(fn (Printer $record): string => PrintJobResource::getUrl('index', ['printer' => $record->id]))
                    ->color('warning')
                    ->badge(),
                TextColumn::make('active_jobs')
                    ->label('Active Jobs')
                    ->getStateUsing(fn (Printer $record): int => $record->printJobs()->whereIn('status', ['queued', 'printing', 'retrying'])->count())
                    ->url(fn (Printer $record): string => PrintJobResource::getUrl('index', ['printer' => $record->id]))
                    ->color('info')
                    ->badge(),
                TextColumn::make('failed_jobs')
                    ->label('Failed Jobs')
                    ->getStateUsing(fn (Printer $record): int => $record->printJobs()->where('status', 'failed')->count())
                    ->url(fn (Printer $record): string => PrintJobResource::getUrl('index', ['printer' => $record->id]))
                    ->color('danger')
                    ->badge(),
                CheckboxColumn::make('is_active')
                    ->label('Active'),
                TextColumn::make('last_state_update')
                    ->label('Last Update')
                    ->dateTime()
                    ->since(),
            ])
            ->filters([
                //
            ])
            ->actions([
                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->searchable(false)
            ->paginated(false);
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
            'index' => Pages\ListPrinters::route('/'),
            'create' => Pages\CreatePrinter::route('/create'),
            'edit' => Pages\EditPrinter::route('/{record}/edit'),
        ];
    }
}
