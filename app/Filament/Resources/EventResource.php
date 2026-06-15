<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EventResource\Pages;
use App\Models\Event;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EventResource extends Resource
{
    protected static ?string $model = Event::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static string|\UnitEnum|null $navigationGroup = 'Events & Registration';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextInput::make('name')
                    ->required()
                    ->columnSpanFull(),
                Select::make('badge_class')
                    ->label('Badge Class')
                    ->helperText('PHP class used for badge generation')
                    ->options([
                        'EF28_Badge' => 'EF28 Badge',
                        'EF29_Badge' => 'EF29 Badge',
                    ])
                    ->columnSpanFull(),
                Group::make([
                    DatePicker::make('starts_at')
                        ->required(),
                    DatePicker::make('ends_at')
                        ->required(),
                ])->columns()->columnSpanFull()->label('Event Dates'),
                Group::make([
                    DateTimePicker::make('order_starts_at')
                        ->label('Order Window Start')
                        ->required()
                        ->helperText('When badge orders can start'),
                    DateTimePicker::make('order_ends_at')
                        ->label('Order Window End')
                        ->helperText('When badge orders must end')
                        ->required(),
                    DateTimePicker::make('mass_printed_at')
                        ->label('Mass Print Date')
                        ->helperText('When the badges were mass printed, if applicable')
                        ->required(),
                ])->columns()->columnSpanFull()->label('Order Management'),

                Group::make([
                    TextInput::make('cost')
                        ->label('Printing Cost (€)')
                        ->helperText('Total printing cost in euros that we need to cover for this event')
                        ->numeric()
                        ->step(0.01)
                        ->suffix('€')
                        ->placeholder('1914.95'),
                ])->columnSpanFull()->label('Financial Tracking'),

                Group::make([
                    Toggle::make('catch_em_all_enabled')
                        ->label('Catch-Em-All Enabled')
                        ->helperText('Enable catch-em-all functionality for this event')
                        ->default(true),
                    Group::make([
                        DateTimePicker::make('catch_em_all_start')
                            ->label('Catch-Em-All Start')
                            ->helperText('When the catch-em-all game should start (leave empty to start with event)')
                            ->nullable(),
                        DateTimePicker::make('catch_em_all_end')
                            ->label('Catch-Em-All End')
                            ->helperText(text: 'When the catch-em-all game should end (leave empty to end with event)')
                            ->nullable(),
                    ])->columns()->columnSpanFull(),
                    Textarea::make('archival_notice')
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
                TextColumn::make('name')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('badge_class')
                    ->label('Badge Class')
                    ->placeholder('Not set')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('starts_at')
                    ->date()
                    ->description(fn (Event $record) => $record->starts_at?->diffForHumans())
                    ->sortable(),
                TextColumn::make('ends_at')
                    ->date()
                    ->description(fn (Event $record) => $record->ends_at?->diffForHumans())
                    ->sortable(),
                TextColumn::make('mass_printed_at')
                    ->dateTime('d.m.Y H:i')
                    ->description(fn (Event $record) => $record->mass_printed_at?->diffForHumans())
                    ->sortable(),
                TextColumn::make('order_starts_at')
                    ->label('Order Start')
                    ->dateTime('d.m.Y H:i')
                    ->description(fn (Event $record) => $record->order_starts_at?->diffForHumans())
                    ->sortable(),
                TextColumn::make('order_ends_at')
                    ->label('Order End')
                    ->dateTime('d.m.Y H:i')
                    ->description(fn (Event $record) => $record->order_ends_at?->diffForHumans())
                    ->sortable(),
                IconColumn::make('catch_em_all_enabled')
                    ->label('Catch-Em-All')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('archival_notice')
                    ->label('Archival Notice')
                    ->limit(50)
                    ->tooltip(fn (Event $record) => $record->archival_notice)
                    ->placeholder('None')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('starts_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageEvents::route('/'),
        ];
    }
}
