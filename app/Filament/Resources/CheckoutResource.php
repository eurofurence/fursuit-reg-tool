<?php

namespace App\Filament\Resources;

use App\Domain\Checkout\Models\Checkout\Checkout;
use App\Domain\Checkout\Models\Checkout\States\Active;
use App\Domain\Checkout\Models\Checkout\States\Cancelled;
use App\Domain\Checkout\Models\Checkout\States\Finished;
use App\Filament\Resources\CheckoutResource\Pages;
use App\Filament\Resources\CheckoutResource\RelationManagers;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class CheckoutResource extends Resource
{
    protected static ?string $model = Checkout::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';
    
    protected static ?string $navigationGroup = 'Sales';
    
    protected static ?int $navigationSort = 10;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Checkout Information')
                    ->schema([
                        Forms\Components\TextInput::make('remote_id')
                            ->label('Remote ID')
                            ->disabled(),
                        
                        Forms\Components\Select::make('user_id')
                            ->label('Customer')
                            ->relationship('user', 'name')
                            ->disabled()
                            ->searchable(),
                        
                        Forms\Components\Select::make('cashier_id')
                            ->label('Cashier')
                            ->relationship('cashier', 'name')
                            ->disabled(),
                        
                        Forms\Components\Select::make('machine_id')
                            ->label('Machine')
                            ->relationship('machine', 'name')
                            ->disabled(),
                        
                        Forms\Components\TextInput::make('status')
                            ->disabled(),
                        
                        Forms\Components\TextInput::make('payment_method')
                            ->disabled(),
                    ])
                    ->columns(2),
                
                Forms\Components\Section::make('Financial Details')
                    ->schema([
                        Forms\Components\TextInput::make('subtotal')
                            ->prefix('€')
                            ->numeric()
                            ->disabled(),
                        
                        Forms\Components\TextInput::make('tax')
                            ->prefix('€')
                            ->numeric()
                            ->disabled(),
                        
                        Forms\Components\TextInput::make('total')
                            ->prefix('€')
                            ->numeric()
                            ->disabled(),
                    ])
                    ->columns(3),
                
                Forms\Components\Section::make('TSE Information')
                    ->schema([
                        Forms\Components\DateTimePicker::make('tse_start_timestamp')
                            ->label('TSE Start')
                            ->disabled(),
                        
                        Forms\Components\DateTimePicker::make('tse_end_timestamp')
                            ->label('TSE End')
                            ->disabled(),
                        
                        Forms\Components\TextInput::make('tse_signature')
                            ->label('TSE Signature')
                            ->disabled()
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->collapsed(),
                
                Forms\Components\Section::make('Timestamps')
                    ->schema([
                        Forms\Components\DateTimePicker::make('created_at')
                            ->disabled(),
                        
                        Forms\Components\DateTimePicker::make('updated_at')
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
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->searchable()
                    ->sortable(),
                
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Customer')
                    ->searchable()
                    ->sortable()
                    ->url(fn ($record) => $record->user ? UserResource::getUrl('index') . '?tableSearch=' . urlencode($record->user->name) : null),
                
                Tables\Columns\TextColumn::make('cashier.name')
                    ->label('Cashier')
                    ->searchable()
                    ->sortable()
                    ->default('-'),
                
                Tables\Columns\TextColumn::make('machine.name')
                    ->label('Machine')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => class_basename($state))
                    ->color(fn ($state) => match (true) {
                        $state instanceof Finished => 'success',
                        $state instanceof Active => 'warning',
                        $state instanceof Cancelled => 'danger',
                        default => 'gray',
                    }),
                
                Tables\Columns\TextColumn::make('payment_method')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'cash' => 'success',
                        'card' => 'info',
                        default => 'gray',
                    }),
                
                Tables\Columns\TextColumn::make('total')
                    ->money('EUR', divideBy: 100)
                    ->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()
                        ->money('EUR', divideBy: 100)
                        ->label('Total')),
                
                Tables\Columns\TextColumn::make('items_count')
                    ->counts('items')
                    ->label('Items'),
                
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        Active::class => 'Active',
                        Finished::class => 'Finished',
                        Cancelled::class => 'Cancelled',
                    ])
                    ->multiple(),
                
                Tables\Filters\SelectFilter::make('payment_method')
                    ->options([
                        'cash' => 'Cash',
                        'card' => 'Card',
                    ]),
                
                Tables\Filters\SelectFilter::make('machine_id')
                    ->label('Machine')
                    ->relationship('machine', 'name'),
                
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('created_from'),
                        Forms\Components\DatePicker::make('created_until'),
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
                    })
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('receipt')
                    ->label('Receipt')
                    ->icon('heroicon-o-document-text')
                    ->color('gray')
                    ->url(fn (Checkout $record): string => route('pos.checkout.receipt', $record))
                    ->openUrlInNewTab(),
                Tables\Actions\Action::make('print')
                    ->label('Print')
                    ->icon('heroicon-o-printer')
                    ->color('info')
                    ->action(function (Checkout $record) {
                        // Generate receipt PDF
                        \App\Jobs\CreateReceiptFromCheckoutJob::dispatchSync($record);
                        
                        // Find active receipt printer
                        $receiptPrinter = \App\Domain\Printing\Models\Printer::where('is_active', true)
                            ->where('type', 'receipt')
                            ->first();

                        if (!$receiptPrinter) {
                            \Filament\Notifications\Notification::make()
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
                            'status' => \App\Enum\PrintJobStatusEnum::Pending,
                        ]);
                        
                        \Filament\Notifications\Notification::make()
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