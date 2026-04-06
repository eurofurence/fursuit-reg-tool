<?php

namespace App\Filament\Resources\StaffResource\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class RfidTagsRelationManager extends RelationManager
{
    protected static string $relationship = 'rfidTags';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextInput::make('content')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255)
                    ->label('RFID Code')
                    ->helperText('The unique identifier from the RFID tag'),
                TextInput::make('name')
                    ->maxLength(255)
                    ->label('Tag Name (Optional)')
                    ->helperText('A friendly name for this RFID tag'),
                Toggle::make('is_active')
                    ->default(true)
                    ->label('Active')
                    ->helperText('Inactive tags cannot be used for authentication'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('content')
            ->columns([
                TextColumn::make('content')
                    ->label('RFID Code')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('name')
                    ->label('Tag Name')
                    ->searchable()
                    ->placeholder('No name set'),
                IconColumn::make('is_active')
                    ->boolean()
                    ->label('Active'),
                TextColumn::make('last_login_at')
                    ->dateTime()
                    ->sortable()
                    ->label('Last Used')
                    ->since()
                    ->placeholder('Never used'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->label('Added')
                    ->since(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Active Status'),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
