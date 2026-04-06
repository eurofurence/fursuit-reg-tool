<?php

namespace App\Filament\Resources;

use App\Domain\Checkout\Models\Checkout\Checkout;
use App\Domain\Checkout\Models\Checkout\States\Active;
use App\Domain\Checkout\Models\Checkout\States\Cancelled;
use App\Domain\Checkout\Models\Checkout\States\Finished;
use App\Domain\Printing\Models\Printer;
use App\Enum\PrintJobStatusEnum;
use App\Filament\Resources\CheckoutResource\Pages;
use App\Filament\Resources\CheckoutResource\RelationManagers;
use App\Jobs\CreateReceiptFromCheckoutJob;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CheckoutResource extends Resource
{
    protected static ?string $model = Checkout::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shopping-cart';

    protected static string|\UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Checkout Information')
                    ->schema([
                        TextInput::make('remote_id')
                            ->label('Remote ID')
                            ->disabled(),

                        Select::make('user_id')
                            ->label('Customer')
                            ->relationship('user', 'name')
                            ->disabled()
                            ->searchable(),

                        Select::make('cashier_id')
                            ->label('Cashier')
                            ->relationship('cashier', 'name')
                            ->disabled(),

                        Select::make('machine_id')
                            ->label('Machine')
                            ->relationship('machine', 'name')
                            ->disabled(),

                        TextInput::make('status')
                            ->disabled(),

                        TextInput::make('payment_method')
                            ->disabled(),
                    ])
                    ->columns(2),

                Section::make('Financial Details')
                    ->schema([
                        TextInput::make('subtotal')
                            ->prefix('€')
                            ->numeric()
                            ->disabled(),

                        TextInput::make('tax')
                            ->prefix('€')
                            ->numeric()
                            ->disabled(),

                        TextInput::make('total')
                            ->prefix('€')
                            ->numeric()
                            ->disabled(),
                    ])
                    ->columns(3),

                Section::make('TSE Information')
                    ->schema([
                        DateTimePicker::make('tse_start_timestamp')
                            ->label('TSE Start')
                            ->disabled(),

                        DateTimePicker::make('tse_end_timestamp')
                            ->label('TSE End')
                            ->disabled(),

                        TextInput::make('tse_signature')
                            ->label('TSE Signature')
                            ->disabled()
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->collapsed(),

                Section::make('Timestamps')
                    ->schema([
                        DateTimePicker::make('created_at')
                            ->disabled(),

                        DateTimePicker::make('updated_at')
                            ->disabled(),
                    ])
                    ->columns(2)
                    ->collapsed(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('user.name')
                    ->label('Customer')
                    ->searchable()
                    ->sortable()
                    ->url(fn ($record) => $record->user ? UserResource::getUrl('index').'?tableSearch='.urlencode($record->user->name) : null),

                TextColumn::make('cashier.name')
                    ->label('Cashier')
                    ->searchable()
                    ->sortable()
                    ->default('-'),

                TextColumn::make('machine.name')
                    ->label('Machine')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => class_basename($state))
                    ->color(fn ($state) => match (true) {
                        $state instanceof Finished => 'success',
                        $state instanceof Active => 'warning',
                        $state instanceof Cancelled => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('payment_method')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'cash' => 'success',
                        'card' => 'info',
                        default => 'gray',
                    }),

                TextColumn::make('total')
                    ->money('EUR', divideBy: 100)
                    ->sortable()
                    ->summarize(Sum::make()
                        ->money('EUR', divideBy: 100)
                        ->label('Total')),

                TextColumn::make('items_count')
                    ->counts('items')
                    ->label('Items'),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        Active::class => 'Active',
                        Finished::class => 'Finished',
                        Cancelled::class => 'Cancelled',
                    ])
                    ->multiple(),

                SelectFilter::make('payment_method')
                    ->options([
                        'cash' => 'Cash',
                        'card' => 'Card',
                    ]),

                SelectFilter::make('machine_id')
                    ->label('Machine')
                    ->relationship('machine', 'name'),

                Filter::make('created_at')
                    ->form([
                        DatePicker::make('created_from'),
                        DatePicker::make('created_until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['created_from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['created_until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
                            );
                    }),
            ])
            ->actions([
                ViewAction::make(),
                Action::make('receipt')
                    ->label('Receipt')
                    ->icon('heroicon-o-document-text')
                    ->color('gray')
                    ->url(fn (Checkout $record): string => route('pos.checkout.receipt', $record))
                    ->openUrlInNewTab(),
                Action::make('print')
                    ->label('Print')
                    ->icon('heroicon-o-printer')
                    ->color('info')
                    ->action(function (Checkout $record) {
                        // Generate receipt PDF
                        CreateReceiptFromCheckoutJob::dispatchSync($record);

                        // Find active receipt printer
                        $receiptPrinter = Printer::where('is_active', true)
                            ->where('type', 'receipt')
                            ->first();

                        if (! $receiptPrinter) {
                            Notification::make()
                                ->title('No receipt printer found')
                                ->body('Please configure an active receipt printer first.')
                                ->danger()
                                ->send();

                            return;
                        }

                        // Create print job
                        $record->printJobs()->create([
                            'printer_id' => $receiptPrinter->id,
                            'type' => 'receipt',
                            'file' => 'checkouts/'.$record->id.'.pdf',
                            'status' => PrintJobStatusEnum::Pending,
                        ]);

                        Notification::make()
                            ->title('Receipt added to print queue')
                            ->body("Receipt for checkout #{$record->id} has been queued for printing.")
                            ->success()
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->modalHeading('Print Receipt')
                    ->modalDescription('This will add the receipt to the print queue.'),
            ])
            ->bulkActions([
                // No bulk actions for checkouts
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCheckouts::route('/'),
            'view' => Pages\ViewCheckout::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false; // Checkouts are created through POS only
    }

    public static function canEdit(Model $record): bool
    {
        return false; // Checkouts should not be edited
    }

    public static function canDelete(Model $record): bool
    {
        return false; // Checkouts should not be deleted
    }
}
