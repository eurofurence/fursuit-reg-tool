<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EventResource\Pages;
use App\Filament\Resources\EventResource\RelationManagers;
use App\Models\Event;
use Filament\Forms;
use Filament\Forms\Components\Group;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class EventResource extends Resource
{
    protected static ?string $model = Event::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->columnSpanFull(),
                Group::make([
                    Forms\Components\DatePicker::make('starts_at')
                        ->required(),
                    Forms\Components\DatePicker::make('ends_at')
                        ->required(),
                ])->columns()->columnSpanFull()->label('Event Dates'),
                Group::make([
                    Forms\Components\DateTimePicker::make('preorder_starts_at')->required(),
                    Forms\Components\DateTimePicker::make('preorder_ends_at')
                        ->required(),
                    Forms\Components\DateTimePicker::make('mass_printed_at')
                        ->required(),
                    Forms\Components\DateTimePicker::make('order_ends_at')
                        ->required(),
                ])->columns()->columnSpanFull()->label('Closing Dates'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('starts_at')
                    ->date()
                    ->description(fn (Event $record) => $record->starts_at?->diffForHumans())
                    ->sortable(),
                Tables\Columns\TextColumn::make('ends_at')
                    ->date()
                    ->description(fn (Event $record) => $record->ends_at?->diffForHumans())
                    ->sortable(),
                Tables\Columns\TextColumn::make('preorder_starts_at')
                    ->dateTime('d.m.Y H:i')
                    ->description(fn (Event $record) => $record->preorder_ends_at?->diffForHumans())
                    ->sortable(),
                Tables\Columns\TextColumn::make('preorder_ends_at')
                    ->dateTime('d.m.Y H:i')
                    ->description(fn (Event $record) => $record->preorder_ends_at?->diffForHumans())
                    ->sortable(),
                Tables\Columns\TextColumn::make('mass_printed_at')
                    ->dateTime('d.m.Y H:i')
                    ->description(fn (Event $record) => $record->mass_printed_at?->diffForHumans())
                    ->sortable(),
                Tables\Columns\TextColumn::make('order_ends_at')
                    ->dateTime('d.m.Y H:i')
                    ->description(fn (Event $record) => $record->order_ends_at?->diffForHumans())
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageEvents::route('/'),
        ];
    }
}
