<?php

namespace App\Http\Controllers;

use App\Domain\Checkout\Models\Checkout\Checkout;
use App\Enum\PrintJobStatusEnum;
use App\Jobs\CreateReceiptFromCheckoutJob;
use App\Notifications\SendReceiptNotification;
use Illuminate\Support\Facades\Storage;

class ReceiptController extends Controller
{
    public function show(Checkout $checkout)
    {
        // Ensure receipt generation has been triggered
        $this->generateReceipt($checkout);

        // Wait for receipt to be generated (max 10 seconds)
        $maxWaitTime = 10;
        $waitedTime = 0;
        while (!Storage::exists('checkouts/'.$checkout->id.'.pdf') && $waitedTime < $maxWaitTime) {
            sleep(1);
            $waitedTime++;
        }

        // If still not generated, return error
        if (!Storage::exists('checkouts/'.$checkout->id.'.pdf')) {
            return redirect()->back()->with('error', 'Receipt is still being generated. Please try again in a moment.');
        }

        // output in browser
        return response($this->getReceipt($checkout), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="receipt.pdf"',
        ]);
    }

    private function getReceipt(Checkout $checkout)
    {
        // check if exists if not generate
        if (! Storage::exists('checkouts/'.$checkout->id.'.pdf')) {
            $this->generateReceipt($checkout);
        }

        return Storage::get('checkouts/'.$checkout->id.'.pdf');
    }

    public function printReceipt(Checkout $checkout)
    {
        // Ensure receipt is generated synchronously before creating print job
        $this->ensureReceiptExists($checkout);

        // Find active receipt printer
        $receiptPrinter = \App\Domain\Printing\Models\Printer::where('is_active', true)
            ->where('type', 'receipt')
            ->first();

        if ($receiptPrinter) {
            // Only add to print queue after confirming the PDF exists
            if (Storage::exists('checkouts/'.$checkout->id.'.pdf')) {
                $checkout->printJobs()->create([
                    'printer_id' => $receiptPrinter->id,
                    'type' => 'receipt',
                    'file' => 'checkouts/'.$checkout->id.'.pdf',
                    'status' => PrintJobStatusEnum::Pending,
                ]);
            } else {
                return redirect()->route('pos.attendee.show', ['attendeeId' => $checkout->user->eventUser()?->attendee_id])
                    ->with('error', 'Receipt generation failed. Please try again.');
            }
        }

        $attendeeId = $checkout->user->eventUser()?->attendee_id;

        return redirect()->route('pos.attendee.show', ['attendeeId' => $attendeeId])->with('success', 'Receipt added to print queue.');
    }

    public function sendEmail(Checkout $checkout)
    {
        // Ensure receipt exists (generate if needed, but async)
        $this->generateReceipt($checkout);
        
        // Queue the email notification (will be sent async thanks to ShouldQueue)
        $checkout->user->notify(new SendReceiptNotification($checkout));

        $attendeeId = $checkout->user->eventUser()?->attendee_id;

        return redirect()->route('pos.attendee.show', ['attendeeId' => $attendeeId])->with('success', 'Receipt will be emailed shortly.');
    }

    private function generateReceipt(Checkout $checkout)
    {
        // Check if receipt already exists
        if (Storage::exists('checkouts/'.$checkout->id.'.pdf')) {
            return; // Receipt already generated
        }

        // Always generate asynchronously to avoid blocking
        CreateReceiptFromCheckoutJob::dispatch($checkout);
    }

    private function ensureReceiptExists(Checkout $checkout)
    {
        // Check if receipt already exists
        if (Storage::exists('checkouts/'.$checkout->id.'.pdf')) {
            return; // Receipt already generated
        }

        // Dispatch the job synchronously to ensure it completes before returning
        CreateReceiptFromCheckoutJob::dispatchSync($checkout);
        
        // Double-check that the file was created
        if (!Storage::exists('checkouts/'.$checkout->id.'.pdf')) {
            // If still doesn't exist, try once more with a small delay
            sleep(1);
            if (!Storage::exists('checkouts/'.$checkout->id.'.pdf')) {
                throw new \Exception('Failed to generate receipt PDF for checkout ' . $checkout->id);
            }
        }
    }
}
