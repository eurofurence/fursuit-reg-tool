<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BadgeResource\Pages;
use App\Jobs\Printing\PrintBadgeJob;
use App\Models\Badge\Badge;
use App\Models\Badge\State_Fulfillment\BadgeFulfillmentStatusState;
use App\Models\Badge\State_Fulfillment\Pending;
use App\Models\Badge\State_Fulfillment\PickedUp;
use App\Models\Badge\State_Fulfillment\Printed;
use App\Models\Badge\State_Fulfillment\ReadyForPickup;
use App\Models\Badge\State_Payment\BadgePaymentStatusState;
use App\Models\Badge\State_Payment\Paid;
use App\Models\Badge\State_Payment\Unpaid;
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
                Forms\Components\Select::make('status_fulfillment')
                    ->label('status_fulfillment')
                    ->options(BadgeFulfillmentStatusState::getStateMapping()->keys()->mapWithKeys(fn($key) => [$key => ucfirst($key)]))
                    ->required(),
                Forms\Components\Select::make('status_payment')
                    ->label('status_payment')
                    ->options(BadgePaymentStatusState::getStateMapping()->keys()->mapWithKeys(fn($key) => [$key => ucfirst($key)]))
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
                Tables\Columns\TextColumn::make('custom_id')
                    ->sortable()
                    ->searchable()
                    ->label('Custom ID'),
                Tables\Columns\TextColumn::make('fursuit.user.attendee_id')
                    ->sortable()
                    ->searchable()
                    ->label('Attendee ID'),
                Tables\Columns\TextColumn::make('fursuit.name')
                    ->searchable()
                    ->label('Fursuit'),
                Tables\Columns\TextColumn::make('status_fulfillment')->badge()->colors([
                    Pending::$name => 'default',
                    Printed::$name => 'success',
                    ReadyForPickup::$name => 'success',
                    PickedUp::$name => 'warning',
                ]),
                Tables\Columns\TextColumn::make('status_payment')->badge()->colors([
                    Unpaid::$name => 'warning',
                    Paid::$name => 'success',
                ]),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status_fulfillment')
                    ->options(BadgeFulfillmentStatusState::getStateMapping()->keys()->mapWithKeys(fn($key) => [ucfirst($key) => $key]))
                    ->label('Badge Fulfillment Status'),
                Tables\Filters\SelectFilter::make('status_payment')
                    ->options(BadgePaymentStatusState::getStateMapping()->keys()->mapWithKeys(fn($key) => [ucfirst($key) => $key]))
                    ->label('Badge Payment Status'),
                // Duplex Bool Filter
                Tables\Filters\TernaryFilter::make('dual_side_print')
                    ->label('Double Sided'),
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
                    ->action(function (Collection $records) {
                        return $records->reverse()->each(fn(Badge $record, $index) => static::printBadge($record, $index));
                    }),
            ])
            ->selectCurrentPageOnly()
            ->paginationPageOptions([10, 25, 50, 100])
            ->defaultSort('fursuit.user.attendee_id', 'asc');
    }

    public static function printBadge(Badge $badge, $mass = 0): Badge
    {
        if ($badge->status_fulfillment->canTransitionTo(Printed::class)) {
            $badge->status_fulfillment->transitionTo(Printed::class);
        }
        // Add delay for mass printing so they are generated in order
        PrintBadgeJob::dispatch($badge)->delay(now()->addSeconds($mass * 15));
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
