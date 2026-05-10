<?php

namespace App\Filament\Resources;

use App\Domain\CatchEmAll\Models\SpecialCode;
use App\Filament\Resources\SpecialCodeResource\Pages;
use App\Models\Event;
use App\Models\Fursuit\Fursuit;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SpecialCodeResource extends Resource
{
    protected static ?string $model = SpecialCode::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-qr-code';

    protected static string|\UnitEnum|null $navigationGroup = 'Events & Registration';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Select::make('event_id')
                    ->label('Event')
                    ->helperText('Event in which the code can be used')
                    ->options(
                        Event::all()->pluck('name', 'id')
                    )
                    ->required()
                    ->columnSpanFull(),
                Select::make('class_name')
                    ->label('Class')
                    ->helperText('PHP class used for code handling')
                    ->options([
                        'App\\Domain\\CatchEmAll\\SpecialActions\\BugBountyAction' => 'Bug Hunter Bounty',
                    ])
                    ->columnSpanFull(),
                Textarea::make('constructor_data')
                    ->label('Constructor Data')
                    ->helperText('Data to be passed to the constructor of the action class')
                    ->rows(3)
                    ->columnSpanFull()
                    ->disabled(fn ($get) => match ($get('class_name')) {
                        'EXAMPLE' => false,
                        default => true
                    })
                    ->placeholder(fn ($get) => match ($get('class_name')) {
                        'EXAMPLE' => '{"amount": 100, "reason": "An Example"}',
                        default => '',
                    })
                    ->rules(['nullable', 'json']),

                TextInput::make('code')
                    ->label('Code')
                    ->helperText('E.g. ABC45')
                    ->maxLength(5)
                    ->minLength(5)
                    ->required()
                    ->unique(ignoreRecord: true, table: 'special_codes', column: 'code')
                    ->rule(fn () => function ($attribute, $value, $fail) {
                        if (Fursuit::where('catch_code', $value)->exists()) {
                            $fail('This code is already used in Fursuits.');
                        }
                    }),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Code')
                    ->sortable(),
                TextColumn::make('class_name')
                    ->label('Class')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'App\\Domain\\CatchEmAll\\SpecialActions\\BugBountyAction' => 'Bug Hunter Bounty',
                        default => $state
                    })
                    ->sortable(),
                TextColumn::make('constructor_data')
                    ->label('Data')
                    ->sortable(),
                TextColumn::make('event_id')
                    ->label('Event')
                    ->formatStateUsing(fn (string $state): string => Event::where('id', $state)->pluck('name')->first())
                    ->sortable(),
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
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageSpecialCodes::route('/'),
        ];
    }
}
