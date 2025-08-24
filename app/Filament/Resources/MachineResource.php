<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MachineResource\Pages;
use App\Models\Machine;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

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
                    ->relationship('sumupReader', 'remote_id')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->remote_id ?? 'Unknown SumUp Reader')
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
                Tables\Columns\SelectColumn::make('sumup_reader_id')
                    ->label('SumUp Reader')
                    ->options(function () {
                        return \App\Models\SumupReader::all()
                            ->pluck('remote_id', 'id')
                            ->map(fn ($remote_id) => $remote_id ?? 'Unknown Reader')
                            ->toArray();
                    })
                    ->placeholder('None assigned')
                    ->selectablePlaceholder(false),
                Tables\Columns\IconColumn::make('should_discover_printers')
                    ->label('Auto-discover Printers')
                    ->boolean(),
            ])
            ->filters([
                //
            ])
            ->searchable(false)
            ->paginated(false)
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([]);
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
}
