<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FursuitResource\Pages;
use App\Filament\Resources\FursuitResource\RelationManagers;
use App\Filament\Traits\HasEventFilter;
use App\Models\Fursuit\Fursuit;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\IconSize;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class FursuitResource extends Resource
{
    use HasEventFilter;

    protected static ?string $model = Fursuit::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-circle';

    protected static string|\UnitEnum|null $navigationGroup = 'Events & Registration';

    protected static ?int $navigationSort = 3;

    public static function getNavigationBadge(): ?string
    {
        $eventId = static::getSelectedEventId();
        if (! $eventId) {
            return null;
        }

        return (string) Fursuit::where('event_id', $eventId)->count();
    }

    public static function getNavigationBadgeColor(): ?string
    {
        $eventId = static::getSelectedEventId();
        if (! $eventId) {
            return null;
        }

        $pendingCount = Fursuit::where('event_id', $eventId)
            ->where('status', 'pending')
            ->count();

        return $pendingCount > 0 ? 'warning' : 'success';
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Group::make([
                    Group::make([
                        ImageEntry::make('image')
                            ->disk('s3')
                            ->height('100%')
                            ->width('100%')
                            ->visibility('private')
                            ->alignCenter(),
                    ])->columnSpan(3),
                    Group::make([
                        TextEntry::make('name')
                            ->label('Name')
                            ->hint('Name of the fursuit on the Badge')
                            ->helperText('Should not contain profanities.')
                            ->size(TextSize::Large)
                            ->weight(FontWeight::Bold),
                        TextEntry::make('species.name')
                            ->label('Species')
                            ->hint('Name of the species on the Badge')
                            ->helperText('Should not contain profanities.')
                            ->size(TextSize::Large)
                            ->weight(FontWeight::Bold),
                        Group::make([
                            IconEntry::make('published')
                                ->size(IconSize::Large)
                                ->hint('Publish your fursuit in our online gallery for everyone to see.')
                                ->boolean(),
                            IconEntry::make('catch_em_all')
                                ->size(IconSize::Large)
                                ->hint('Participate in the convention game to be catchable by other attendees.')
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

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Select::make('user_id')
                    ->relationship('user', 'name')
                    ->required(),
                Select::make('species_id')
                    ->relationship('species', 'name')
                    ->required(),
                TextInput::make('event_id')
                    ->required()
                    ->numeric(),
                TextInput::make('status')
                    ->required()
                    ->maxLength(255),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                FileUpload::make('image')
                    ->image()
                    ->required(),
                Toggle::make('published')
                    ->required(),
                Toggle::make('catch_em_all')
                    ->required(),
                DateTimePicker::make('approved_at'),
                DateTimePicker::make('rejected_at'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => static::applyEventFilter($query))
            ->columns([
                TextColumn::make('user.name')
                    ->label('By')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('species.name')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (Fursuit $fursuit) => $fursuit->status->color())
                    ->formatStateUsing(fn ($state) => ucfirst($state))
                    ->searchable(),
                TextColumn::make('name')
                    ->searchable(),
                ImageColumn::make('image')
                    ->disk('s3')
                    ->visibility('private')
                    ->circular()
                    ->checkFileExistence(false),
                IconColumn::make('published')
                    ->boolean(),
                IconColumn::make('catch_em_all')
                    ->boolean(),
            ])
            ->filters([
                // Status Filter
                SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                    ])
                    ->default('pending'),
            ])
            ->actions([
                ViewAction::make(),
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
