<?php

namespace App\Filament\Resources;

use App\Domain\Printing\Models\Printer;
use App\Filament\Resources\PrinterResource\Pages;
use App\Filament\Resources\PrinterResource\RelationManagers;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PrinterResource extends Resource
{
    protected static ?string $model = Printer::class;
    protected static ?string $navigationGroup = 'POS';
    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->columnSpanFull()
                    ->maxLength(255),
                Forms\Components\Select::make('type')
                    ->options([
                        'receipt' => 'Receipt',
                        'badge' => 'Badge',
                    ])
                    ->required()
                    ->columnSpanFull(),
                Forms\Components\Select::make('machine_id')
                    ->label('Machine')
                    ->relationship('machine', 'name')
                    ->required()
                    ->columnSpanFull(),
                Forms\Components\Select::make('default_paper_size')
                    ->options(fn(Printer $record) => collect($record->paper_sizes)->pluck('name', 'name'))
                    ->columnSpanFull(),
                // Json paper_sizes only view
                Forms\Components\Textarea::make('paper_sizes')
                    ->disabled()
                    ->formatStateUsing(function ($state) {
                        return json_encode($state, JSON_PRETTY_PRINT);
                    })
                    ->default('{}')
                    ->rows(10)
                    ->columnSpanFull(),
                Forms\Components\Checkbox::make('is_active')
                    ->columnSpanFull(),
                Forms\Components\Checkbox::make('is_double')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('type'),
                Tables\Columns\TextColumn::make('machine.name')->label('Machine'),

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
            'index' => Pages\ListPrinters::route('/'),
            'create' => Pages\CreatePrinter::route('/create'),
            'edit' => Pages\EditPrinter::route('/{record}/edit'),
        ];
    }
}
