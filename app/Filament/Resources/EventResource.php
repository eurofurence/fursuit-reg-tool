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
                    Forms\Components\DateTimePicker::make('order_starts_at')
                        ->label('Order Window Start')
                        ->required()
                        ->helperText('When badge orders can start'),
                    Forms\Components\DateTimePicker::make('order_ends_at')
                        ->label('Order Window End')
                        ->helperText('When badge orders must end')
                        ->required(),
                    Forms\Components\DateTimePicker::make('mass_printed_at')
                        ->label('Mass Print Date')
                        ->helperText('When the badges were mass printed, if applicable')
                        ->required(),
                ])->columns()->columnSpanFull()->label('Order Management'),
                
                Group::make([
                    Forms\Components\Toggle::make('catch_em_all_enabled')
                        ->label('Catch-Em-All Enabled')
                        ->helperText('Enable catch-em-all functionality for this event')
                        ->default(true),
                    Forms\Components\Textarea::make('archival_notice')
                        ->label('Archival Notice')
                        ->helperText('Notice to display for archival/historical events')
                        ->rows(3)
                        ->columnSpanFull(),
                ])->columns()->columnSpanFull()->label('Gallery Settings'),
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
                Tables\Columns\TextColumn::make('mass_printed_at')
                    ->dateTime('d.m.Y H:i')
                    ->description(fn (Event $record) => $record->mass_printed_at?->diffForHumans())
                    ->sortable(),
                Tables\Columns\TextColumn::make('order_starts_at')
                    ->label('Order Start')
                    ->dateTime('d.m.Y H:i')
                    ->description(fn (Event $record) => $record->order_starts_at?->diffForHumans())
                    ->sortable(),
                Tables\Columns\TextColumn::make('order_ends_at')
                    ->label('Order End')
                    ->dateTime('d.m.Y H:i')
                    ->description(fn (Event $record) => $record->order_ends_at?->diffForHumans())
                    ->sortable(),
                Tables\Columns\IconColumn::make('catch_em_all_enabled')
                    ->label('Catch-Em-All')
                    ->boolean(),
                Tables\Columns\TextColumn::make('archival_notice')
                    ->label('Archival Notice')
                    ->limit(50)
                    ->tooltip(fn (Event $record) => $record->archival_notice)
                    ->placeholder('None'),
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
