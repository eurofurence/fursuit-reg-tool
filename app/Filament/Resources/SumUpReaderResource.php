<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SumUpReaderResource\Pages;
use App\Models\SumUpReader;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class SumUpReaderResource extends Resource
{
    protected static ?string $model = SumUpReader::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-credit-card';

    protected static string|\UnitEnum|null $navigationGroup = 'POS';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->columnSpanFull()
                    ->maxLength(255),
                Forms\Components\TextInput::make('remote_id')
                    ->columnSpanFull()
                    ->readOnly(),
                Forms\Components\TextInput::make('paring_code')
                    ->required()
                    ->columnSpanFull()
                    ->maxLength(255),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name'),
                Tables\Columns\TextColumn::make('remote_id'),
                Tables\Columns\TextColumn::make('paring_code'),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
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
            'index' => Pages\ListSumUpReaders::route('/'),
            'create' => Pages\CreateSumUpReader::route('/create'),
            'edit' => Pages\EditSumUpReader::route('/{record}/edit'),
        ];
    }
}
