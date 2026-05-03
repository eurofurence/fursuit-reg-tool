<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MachineResource\Pages;
use App\Models\Machine;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MachineResource extends Resource
{
    protected static ?string $model = Machine::class;

    protected static ?string $navigationGroup = 'POS';

    protected static ?string $navigationIcon = 'heroicon-o-computer-desktop';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->columnSpanFull()
                    ->maxLength(255),
                // TSE Client
                Forms\Components\Select::make('tse_client_id')
                    ->label('TSE Client')
                    ->relationship('tseClient', 'remote_id')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->remote_id ?? 'Unknown TSE Client')
                    ->columnSpanFull(),
                // SumUp Reader
                Forms\Components\Select::make('sumup_reader_id')
                    ->label('SumUp Reader')
                    ->relationship('sumupReader', 'name')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->name ?? 'Unknown SumUp Reader')
                    ->columnSpanFull(),
                Forms\Components\Checkbox::make('should_discover_printers')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('tseClient.remote_id')
                    ->label('TSE Client')
                    ->placeholder('None assigned'),
                Tables\Columns\TextColumn::make('sumupReader.name')
                    ->label('SumUp Reader')
                    ->placeholder('None assigned'),
                Tables\Columns\IconColumn::make('should_discover_printers')
                    ->label('Auto-discover Printers')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('archived')
                    ->label('Archived')
                    ->placeholder('Active machines')
                    ->trueLabel('Archived machines')
                    ->falseLabel('All machines')
                    ->queries(
                        true: fn (Builder $query) => $query->onlyArchived(),
                        false: fn (Builder $query) => $query->withArchived(),
                        blank: fn (Builder $query) => $query->notArchived(),
                    ),
            ])
            ->searchable(false)
            ->paginated(false)
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('archive')
                    ->label('Archive')
                    ->icon('heroicon-o-archive-box')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Archive Machine')
                    ->modalDescription('Are you sure you want to archive this machine? It will be hidden from normal view.')
                    ->modalSubmitActionLabel('Yes, archive it')
                    ->action(fn (Machine $record) => $record->archive())
                    ->visible(fn (Machine $record) => !$record->isArchived()),
                Tables\Actions\Action::make('unarchive')
                    ->label('Restore')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Restore Machine')
                    ->modalDescription('Are you sure you want to restore this machine? It will be visible again.')
                    ->modalSubmitActionLabel('Yes, restore it')
                    ->action(fn (Machine $record) => $record->unarchive())
                    ->visible(fn (Machine $record) => $record->isArchived()),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('archive')
                    ->label('Archive selected')
                    ->icon('heroicon-o-archive-box')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Archive Machines')
                    ->modalDescription('Are you sure you want to archive the selected machines? They will be hidden from normal view and unable to log in to the POS system.')
                    ->modalSubmitActionLabel('Yes, archive them')
                    ->action(fn ($records) => $records->each->archive())
                    ->deselectRecordsAfterCompletion(),
                Tables\Actions\BulkAction::make('unarchive')
                    ->label('Restore selected')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Restore Machines')
                    ->modalDescription('Are you sure you want to restore the selected machines? They will be visible again and able to log in to the POS system.')
                    ->modalSubmitActionLabel('Yes, restore them')
                    ->action(fn ($records) => $records->each->unarchive())
                    ->deselectRecordsAfterCompletion(),
            ]);
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
            'index' => Pages\ListMachines::route('/'),
            'create' => Pages\CreateMachine::route('/create'),
            'edit' => Pages\EditMachine::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
    }
}
