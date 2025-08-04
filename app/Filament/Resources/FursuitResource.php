<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FursuitResource\Pages;
use App\Filament\Resources\FursuitResource\RelationManagers;
use App\Models\Event;
use App\Models\Fursuit\Fursuit;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Components\Group;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;

class FursuitResource extends Resource
{
    protected static ?string $model = Fursuit::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Group::make([
                    Group::make([
                        ImageEntry::make('image')
                            ->disk('s3')
                            ->height('100%')
                            ->width('100%')
                            ->visibility('private')
                            ->alignCenter(),
                        TextEntry::make('rules')
                            ->listWithLineBreaks()
                            ->getStateUsing(fn () => [
                                'Fursuits in your possession.',
                                'No humans in the photos.',
                                'No explicit content.',
                                'No drawings or illustrations.',
                                'No AI-generated images.',
                            ])
                            ->bulleted(),
                    ])->columnSpan(3),
                    Group::make([
                        TextEntry::make('name')
                            ->label('Name')
                            ->hint('Name of the fursuit on the Badge')
                            ->helperText('Should not contain profanities.')
                            ->size(TextEntry\TextEntrySize::Large)
                            ->weight(FontWeight::Bold),
                        TextEntry::make('species.name')
                            ->label('Species')
                            ->hint('Name of the species on the Badge')
                            ->helperText('Should not contain profanities.')
                            ->size(TextEntry\TextEntrySize::Large)
                            ->weight(FontWeight::Bold),
                        Group::make([
                            IconEntry::make('published')
                                ->size(IconEntry\IconEntrySize::Large)
                                ->hint('Share this fursuit on the website.')
                                ->boolean(),
                            IconEntry::make('catch_em_all')
                                ->size(IconEntry\IconEntrySize::Large)
                                ->hint('Partakes in the Catch-em-All.')
                                ->boolean(),
                        ])->columns(),
                        // Status Badge
                        TextEntry::make('status')
                            ->badge()
                            ->hint('Current status of the fursuit.')
                            ->color(fn (Fursuit $fursuit) => $fursuit->status->color())
                            ->formatStateUsing(fn ($state) => ucfirst($state)),
                    ])->columnSpan(9),
                ])->columns(12)->columnSpanFull(),
            ]);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('user_id')
                    ->relationship('user', 'name')
                    ->required(),
                Forms\Components\Select::make('species_id')
                    ->relationship('species', 'name')
                    ->required(),
                Forms\Components\TextInput::make('event_id')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('status')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\FileUpload::make('image')
                    ->image()
                    ->required(),
                Forms\Components\Toggle::make('published')
                    ->required(),
                Forms\Components\Toggle::make('catch_em_all')
                    ->required(),
                Forms\Components\DateTimePicker::make('approved_at'),
                Forms\Components\DateTimePicker::make('rejected_at'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('By')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('species.name')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (Fursuit $fursuit) => $fursuit->status->color())
                    ->formatStateUsing(fn ($state) => ucfirst($state))
                    ->searchable(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\ImageColumn::make('image')
                    ->disk('s3')
                    ->visibility('private')
                    ->circular()
                    ->checkFileExistence(false),
                Tables\Columns\IconColumn::make('published')
                    ->boolean(),
                Tables\Columns\IconColumn::make('catch_em_all')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('event_id')
                    ->relationship('event', 'name')
                    ->default(Event::getActiveEvent()?->id),
                // Status Filter
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                    ])
                    ->default('pending'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                // Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ActivitiesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFursuits::route('/'),
            'create' => Pages\CreateFursuit::route('/create'),
            'view' => Pages\ViewFursuit::route('/{record}'),
            'edit' => Pages\EditFursuit::route('/{record}/edit'),
        ];
    }
}
