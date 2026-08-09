<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserProfileResource\Pages;
use App\Filament\Resources\UserProfileResource\RelationManagers;
use App\Models\UserProfile\States\Rejected;
use App\Models\UserProfile\UserProfile;
use Filament\Infolists\Components\Group;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UserProfileResource extends Resource
{
    protected static ?string $model = UserProfile::class;

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationGroup = 'Events & Registration';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Catch-Em-All Profiles';

    protected static ?string $modelLabel = 'User Profile';

    public static function getNavigationBadge(): ?string
    {
        $pendingCount = static::pendingQuery()->count();

        return $pendingCount > 0 ? (string) $pendingCount : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function pendingQuery(): Builder
    {
        return UserProfile::query()
            ->whereNull('approved_at')
            ->whereNull('rejected_at');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Group::make([
                    ImageEntry::make('user.avatar_url')
                        ->label('Avatar')
                        ->hint('Part of the review: a changed avatar sends the profile back to pending.')
                        ->placeholder('No avatar')
                        ->circular(),
                    TextEntry::make('user.name')
                        ->label('User')
                        ->size(TextEntry\TextEntrySize::Large)
                        ->weight(FontWeight::Bold),
                    TextEntry::make('status')
                        ->badge()
                        ->hint('Approving publishes the description and all links at once.')
                        ->color(fn (UserProfile $record) => $record->status->color())
                        ->formatStateUsing(fn ($state) => ucfirst($state)),
                    TextEntry::make('rejection_reason')
                        ->label('Rejection reason')
                        ->hint('Shown to the profile owner.')
                        ->visible(fn (UserProfile $record) => $record->status instanceof Rejected)
                        ->columnSpanFull(),
                    TextEntry::make('description')
                        ->label('Description')
                        ->hint('Shown publicly on the Catch-Em-All profile. Should not contain profanities.')
                        ->placeholder('No description')
                        ->columnSpanFull(),
                    RepeatableEntry::make('links')
                        ->label('Links')
                        ->hint('Open each link and check where it leads before approving.')
                        ->placeholder('No links')
                        ->schema([
                            TextEntry::make('url')
                                ->hiddenLabel()
                                ->url(fn (?string $state) => $state, true),
                        ])
                        ->columnSpanFull(),
                    TextEntry::make('updated_at')
                        ->label('Last changed')
                        ->dateTime(),
                ])->columns()->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('user.avatar_url')
                    ->label('Avatar')
                    ->circular(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('User')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (UserProfile $record) => $record->status->color())
                    ->formatStateUsing(fn ($state) => ucfirst($state)),
                Tables\Columns\TextColumn::make('description')
                    ->limit(50)
                    ->placeholder('No description')
                    ->searchable(),
                Tables\Columns\TextColumn::make('links_count')
                    ->counts('links')
                    ->label('Links'),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Last changed')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('updated_at')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                    ])
                    ->default('pending')
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'pending' => $query->whereNull('approved_at')->whereNull('rejected_at'),
                        'approved' => $query->whereNotNull('approved_at'),
                        'rejected' => $query->whereNotNull('rejected_at'),
                        default => $query,
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
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
            'index' => Pages\ListUserProfiles::route('/'),
            'view' => Pages\ViewUserProfile::route('/{record}'),
        ];
    }
}
