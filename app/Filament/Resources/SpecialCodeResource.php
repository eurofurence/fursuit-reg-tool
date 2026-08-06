<?php

namespace App\Filament\Resources;

use App\Domain\CatchEmAll\Enums\SpecialCodeType;
use App\Domain\CatchEmAll\Models\SpecialCode;
use App\Domain\CatchEmAll\SpecialActions\SpecialActionsRegister;
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

    protected static ?string $navigationGroup = 'Events & Registration';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('event_id')
                    ->label('Event')
                    ->helperText('Event in which the code can be used')
                    ->options(
                        \App\Models\Event::all()->sortByDesc('name')->pluck('name', 'id')
                    )
                    ->default(\App\Models\Event::latest('starts_at')->first()?->id)
                    ->required()
                    ->columnSpanFull(),
                Forms\Components\Select::make('type')
                    ->label('Type')
                    ->helperText('PHP class used for code handling')
                    ->options(
                        SpecialActionsRegister::getFillamentOptions())
                    ->columnSpanFull(),
                Forms\Components\Textarea::make('constructor_data')
                    ->label('Constructor Data')
                    ->helperText('Data to be passed to the constructor of the action class')
                    ->rows(3)
                    ->columnSpanFull()
                    ->disabled(fn ($get) => $get('type') && SpecialActionsRegister::getConfigDataForSpecialCodeType(SpecialCodeType::tryFrom($get('type'))) === null)
                    ->placeholder(fn ($get) => $get('type') ? (SpecialActionsRegister::getConfigDataForSpecialCodeType(SpecialCodeType::tryFrom($get('type'))) ? json_encode(SpecialActionsRegister::getConfigDataForSpecialCodeType(SpecialCodeType::tryFrom($get('type'))), JSON_PRETTY_PRINT) : 'No data required for this type.') : 'Nothing selected')
                    ->rules(['nullable', 'json']),
                Forms\Components\TextInput::make('code')
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

                Forms\Components\TextInput::make('catch_url')
                    ->label('Catch URL')
                    ->helperText('URL to catch the fursuiter with this code')
                    ->readOnly()
                    ->columnSpanFull()
                    ->dehydrated(false)
                    ->formatStateUsing(fn ($state, $get) => self::buildCatchAutoUrl($get('code') ?? '')),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Code')
                    ->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->label('Type')
                    ->formatStateUsing(fn (SpecialCodeType $state): string => SpecialActionsRegister::getDisplayNameForSpecialCodeType($state) ?? $state->name)
                    ->sortable(),
                Tables\Columns\TextColumn::make('constructor_data')
                    ->label('Data')
                    ->sortable(),
                Tables\Columns\TextColumn::make('event_id')
                    ->label('Event')
                    ->formatStateUsing(fn (string $state): string => \App\Models\Event::where('id', $state)->pluck('name')->first())
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

    private static function buildCatchAutoUrl(string $code): string
    {
        $scheme = parse_url(config('app.url'), PHP_URL_SCHEME) ?: 'https';
        $baseDomain = (string) config('fcea.domain', 'catch.localhost');

        return sprintf(
            '%s://%s/?code=%s&auto',
            $scheme,
            $baseDomain,
            urlencode($code)
        );
    }
}
