<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BadgeResource\Pages;
use App\Jobs\Printing\PrintBadgeJob;
use App\Models\Badge\Badge;
use App\Models\Badge\States\BadgeStatusState;
use App\Models\Badge\States\Pending;
use App\Models\Badge\States\PickedUp;
use App\Models\Badge\States\Printed;
use App\Models\Badge\States\ReadyForPickup;
use App\Models\Fursuit\States\FursuitStatusState;
use App\Models\Machine;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Collection;

class BadgeResource extends Resource
{
    protected static ?string $model = Badge::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('fursuit_id')
                    ->label('Fursuit')
                    ->disabled()
                    ->relationship('fursuit', 'name')
                    ->required(),
                // Status
                Forms\Components\Select::make('status')
                    ->label('Status')
                    ->options(BadgeStatusState::getStateMapping()->keys()->mapWithKeys(fn($key) => [$key => ucfirst($key)]))
                    ->required(),
                Forms\Components\Group::make([
                    // Total
                    Forms\Components\TextInput::make('total')
                        ->label('Total'),
                    // Tax
                    Forms\Components\TextInput::make('tax')
                        ->label('Tax')
                        ->disabled(),
                    // Subtotal
                    Forms\Components\TextInput::make('subtotal')
                        ->label('Sub-Total')
                        ->disabled(),
                ])->columnSpanFull()->columns(3)
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('fursuit.user.attendee_id')
                    ->sortable()
                    ->label('Fursuit'),
                Tables\Columns\TextColumn::make('fursuit.name')
                    ->label('Fursuit'),
                Tables\Columns\TextColumn::make('status')->badge()->colors([
                    Pending::$name => 'default',
                    Printed::$name => 'success',
                    ReadyForPickup::$name => 'success',
                    PickedUp::$name => 'warning',
                ]),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(BadgeStatusState::getStateMapping()->keys()->mapWithKeys(fn($key) => [ucfirst($key) => $key]))
                    ->label('Badge Status'),
                Tables\Filters\SelectFilter::make('fursuit_status')
                    ->options(FursuitStatusState::getStateMapping()->keys()->mapWithKeys(fn($key) => [$key => ucfirst($key)]))
                    ->query(function (Builder $query, array $data) {
                        $query->when($data, fn($query, $data) => $query->whereHas('fursuit', function (Builder $query) use ($data) {
                            $query->where('status', $data);
                        }));
                    })
                    ->label('Fursuit Status'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('printBadge')
                    ->color('warning')
                    ->icon('heroicon-o-printer')
                    ->requiresConfirmation(true)
                    ->label('Print Badge')
                    ->action(function (Badge $record) {
                        return static::printBadge($record);
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
                Tables\Actions\BulkAction::make('printBadgeBulk')
                    ->label('Print Badge')
                    ->requiresConfirmation()
                    ->action(fn(Collection $records) => $records->each(fn(Badge $record) => static::printBadge($record))),
            ])
            ->selectCurrentPageOnly()
            ->paginationPageOptions([10, 25, 50, 100])
            ->defaultSort('fursuit.user.attendee_id', 'asc');
    }

    public static function printBadge(Badge $badge)
    {
        if ($badge->status !== Printed::class && $badge->status->canTransitionTo(Printed::class)) {
            $badge->status->transitionTo(Printed::class);
        }
        PrintBadgeJob::dispatch($badge, Machine::first());
        return $badge;
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBadges::route('/'),
            'create' => Pages\CreateBadge::route('/create'),
            'edit' => Pages\EditBadge::route('/{record}/edit'),
        ];
    }
}
