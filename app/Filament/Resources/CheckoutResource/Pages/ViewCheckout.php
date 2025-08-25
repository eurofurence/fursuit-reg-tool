<?php

namespace App\Filament\Resources\CheckoutResource\Pages;

use App\Domain\Checkout\Models\Checkout\Checkout;
use App\Filament\Resources\CheckoutResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewCheckout extends ViewRecord
{
    protected static string $resource = CheckoutResource::class;
    
    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('receipt')
                ->label('Download Receipt')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->url(fn (Checkout $record): string => route('pos.checkout.receipt', $record))
                ->openUrlInNewTab(),
            
            Actions\Action::make('print')
                ->label('Print Receipt')
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
        ];
    }
}