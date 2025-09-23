<?php

namespace App\Filament\Resources;

use App\Domain\CatchEmAll\Models\SpecialCode;
use App\Filament\Resources\SpecialCodeResource\Pages;
use App\Models\Fursuit\Fursuit;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SpecialCodeResource extends Resource
{
    protected static ?string $model = SpecialCode::class;

    protected static ?string $navigationIcon = 'heroicon-o-qr-code';

    protected static ?string $navigationGroup = 'Catch \'Em All';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('event_id')
                    ->label('Event')
                    ->helperText('Event in which the code can be used')
                    ->options(
                        \App\Models\Event::all()->pluck('name', 'id')
                    )
                    ->required()
                    ->columnSpanFull(),
                Forms\Components\Select::make('class_name')
                    ->label('Class')
                    ->helperText('PHP class used for code handling')
                    ->options([
                        'App\\Domain\\CatchEmAll\\SpecialActions\\BugBountyAction' => 'Bug Hunter Bounty',
                    ])
                    ->columnSpanFull(),
                Forms\Components\Textarea::make('constructor_data')
                    ->label('Constructor Data')
                    ->helperText('Data to be passed to the constructor of the action class')
                    ->rows(3)
                    ->columnSpanFull()
                    ->disabled(fn($get) => match ($get('class_name')) {
                        'EXAMPLE' => false,
                        default => true
                    })
                    ->placeholder(fn($get) => match ($get('class_name')) {
                        'EXAMPLE' => '{"amount": 100, "reason": "An Example"}',
                        default => '',
                    })
                    ->rules(['nullable', 'json']),

                Forms\Components\TextInput::make('code')
                    ->label('Code')
                    ->helperText('E.g. ABC45')
                    ->maxLength(5)
                    ->minLength(5)
                    ->required()
                    ->unique(ignoreRecord: true, table: 'special_codes', column: 'code')
                    ->rule(fn() => function ($attribute, $value, $fail) {
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
                Tables\Columns\TextColumn::make('code')
                    ->label('Code')
                    ->sortable(),
                Tables\Columns\TextColumn::make('class_name')
                    ->label('Class')
                    ->formatStateUsing(fn(string $state): string => match ($state) {
                        'App\\Domain\\CatchEmAll\\SpecialActions\\BugBountyAction' => 'Bug Hunter Bounty',
                        default => $state
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('constructor_data')
                    ->label('Data')
                    ->sortable(),
                Tables\Columns\TextColumn::make('event_id')
                    ->label('Event')
                    ->formatStateUsing(fn(string $state): string => \App\Models\Event::where('id', $state)->pluck('name')->first())
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
