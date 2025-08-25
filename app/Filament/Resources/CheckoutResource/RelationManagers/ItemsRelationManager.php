<?php

namespace App\Filament\Resources\CheckoutResource\RelationManagers;

use App\Filament\Resources\BadgeResource;
use App\Models\Badge\Badge;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Checkout Items';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Item')
                    ->searchable(),
                
                Tables\Columns\TextColumn::make('description')
                    ->label('Features')
                    ->formatStateUsing(function ($state) {
                        if (is_array($state) && !empty($state)) {
                            return implode(', ', $state);
                        }
                        return '-';
                    })
                    ->wrap(),
                
                Tables\Columns\TextColumn::make('payable')
                    ->label('Badge')
                    ->formatStateUsing(function ($record) {
                        if ($record->payable_type === Badge::class && $record->payable) {
                            $badge = $record->payable;
                            return "{$badge->fursuit->name} (#{$badge->custom_id})";
                        }
                        return '-';
                    })
                    ->url(function ($record) {
                        if ($record->payable_type === Badge::class && $record->payable) {
                            return BadgeResource::getUrl('edit', ['record' => $record->payable]);
                        }
                        return null;
                    })
                    ->openUrlInNewTab(),
                
                Tables\Columns\TextColumn::make('subtotal')
                    ->label('Subtotal')
                    ->money('EUR', divideBy: 100)
                    ->alignEnd(),
                
                Tables\Columns\TextColumn::make('tax')
                    ->label('Tax')
                    ->money('EUR', divideBy: 100)
                    ->alignEnd(),
                
                Tables\Columns\TextColumn::make('total')
                    ->label('Total')
                    ->money('EUR', divideBy: 100)
                    ->alignEnd()
                    ->weight('bold'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                // No header actions - items are created with checkout
            ])
            ->actions([
                // No actions - items are read-only
            ])
            ->bulkActions([
                // No bulk actions
            ])
            ->paginated(false);
    }
    
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return true;
    }
    
    protected function canCreate(): bool
    {
        return false;
    }
    
    protected function canEdit(Model $record): bool
    {
        return false;
    }
    
    protected function canDelete(Model $record): bool
    {
        return false;
    }
}