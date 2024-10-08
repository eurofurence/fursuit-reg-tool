<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MachineResource\Pages;
use App\Filament\Resources\MachineResource\RelationManagers;
use App\Models\Machine;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Http;

class MachineResource extends Resource
{
    protected static ?string $model = Machine::class;

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
                Forms\Components\Select::make('receipt_printer_id')
                    ->label('Receipt Printer')
                    ->relationship('receiptPrinter', 'name')
                    ->columnSpanFull(),
                Forms\Components\Select::make('badge_printer_id')
                    ->label('Badge Printer')
                    ->relationship('badgePrinter', 'name')
                    ->columnSpanFull(),
                // TSE Client
                Forms\Components\Select::make('tse_client_id')
                    ->label('TSE Client')
                    ->relationship('tseClient', 'remote_id')
                    ->columnSpanFull(),
                // SumUp Reader
                Forms\Components\Select::make('sumup_reader_id')
                    ->label('SumUp Reader')
                    ->relationship('sumupReader', 'remote_id')
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
