<?php

namespace App\Filament\Resources;

use App\Domain\Checkout\Models\TseClient;
use App\Filament\Resources\TseClientResource\Pages;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TseClientResource extends Resource
{
    protected static ?string $model = TseClient::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static string|\UnitEnum|null $navigationGroup = 'POS';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextInput::make('remote_id')
                    ->label('Remote ID')
                    ->required(),
                TextInput::make('serial_number')
                    ->label('Serial Number')
                    ->required(),
                Select::make('state')
                    ->label('State')
                    ->options([
                        'REGISTERED' => 'Registered',
                        'DEREGISTERED' => 'Deregistered',
                    ])
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('remote_id')
                    ->searchable()
                    ->label('Remote ID'),
                TextColumn::make('serial_number')
                    ->searchable()
                    ->label('Serial Number'),
                TextColumn::make('state')
                    ->searchable()
                    ->label('State'),
            ])
            ->filters([

            ])
            ->actions([
                EditAction::make(),
            ])
            ->bulkActions([
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
            'index' => Pages\ListTseClients::route('/'),
            'create' => Pages\CreateTseClient::route('/create'),
            'edit' => Pages\EditTseClient::route('/{record}/edit'),
        ];
    }
}
