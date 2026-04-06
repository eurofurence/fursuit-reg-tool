<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StaffResource\Pages;
use App\Filament\Resources\StaffResource\RelationManagers;
use App\Models\Staff;
use App\Rules\SecurePinRule;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class StaffResource extends Resource
{
    protected static ?string $model = Staff::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static string|\UnitEnum|null $navigationGroup = 'POS';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('pin_code')
                    ->nullable()
                    ->numeric()
                    ->length(6)
                    ->label('PIN Code (6 digits)')
                    ->helperText('Enter a secure 6-digit PIN code. Leave empty to require setup code first.')
                    ->rules([new SecurePinRule]),
                TextInput::make('setup_code')
                    ->nullable()
                    ->length(6)
                    ->label('Setup Code')
                    ->helperText('6-character alphanumeric code for initial account setup. Auto-generated if left empty.')
                    ->extraAttributes(['style' => 'text-transform: uppercase'])
                    ->mutateDehydratedStateUsing(fn ($state) => strtoupper($state ?? ''))
                    ->suffixAction(
                        Action::make('generate_setup_code')
                            ->label('Generate')
                            ->icon('heroicon-m-arrow-path')
                            ->action(function (Set $set, ?Staff $record) {
                                if ($record) {
                                    $code = $record->generateSetupCode();
                                } else {
                                    // Generate code without saving for new records
                                    do {
                                        $code = strtoupper(substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 6));
                                    } while (Staff::where('setup_code', $code)->exists());
                                }
                                $set('setup_code', $code);
                            })
                            ->visible(fn ($record) => ! $record || ! $record->pin_code)
                    ),
                Toggle::make('is_active')
                    ->default(true)
                    ->label('Active')
                    ->helperText('Inactive staff cannot login to POS'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('pin_code')
                    ->label('PIN Code')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->formatStateUsing(fn ($state) => $state ? 'Set' : 'Not Set'),
                IconColumn::make('is_active')
                    ->boolean()
                    ->label('Active'),
                TextColumn::make('rfid_tags_count')
                    ->counts('rfidTags')
                    ->label('RFID Tags'),
                TextColumn::make('last_login_at')
                    ->dateTime()
                    ->sortable()
                    ->label('Last Login')
                    ->since(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Active Status'),
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
            ->paginated(false);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\RfidTagsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStaff::route('/'),
            'create' => Pages\CreateStaff::route('/create'),
            'edit' => Pages\EditStaff::route('/{record}/edit'),
        ];
    }
}
