<?php

namespace App\Http\Controllers;

use Apirone\Lib\PhpQRCode\QRCode;
use App\Domain\Checkout\Models\Checkout\Checkout;
use App\Domain\Printing\Models\PrintJob;
use App\Enum\PrintJobStatusEnum;
use App\Jobs\CreateReceiptFromCheckoutJob;
use App\Notifications\SendReceiptNotification;
use Illuminate\Support\Facades\Storage;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;

class ReceiptController extends Controller
{
    public function show(Checkout $checkout)
    {
        $this->generateReceipt($checkout);
        // output in browser
        return response($this->getReceipt($checkout), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="receipt.pdf"',
        ]);
    }

    private function getReceipt(Checkout $checkout)
    {
        // check if exists if not generate
        if (!Storage::exists('checkouts/'.$checkout->id . '.pdf')) {
            $this->generateReceipt();
        }
        return Storage::get('checkouts/'.$checkout->id . '.pdf');
    }

    public function printReceipt(Checkout $checkout)
    {
        $this->generateReceipt($checkout);
        $checkout->printJobs()->create([
            'printer_id' => $checkout->machine->receipt_printer_id,
            'type' => 'receipt',
            'file' => 'checkouts/'.$checkout->id . '.pdf',
            'status' => PrintJobStatusEnum::Pending,
        ]);
        return redirect()->route('pos.attendee.show', ['attendeeId' => $checkout->user->attendee_id])->with('success', 'Receipt added to print queue.');
    }

    public function sendEmail(Checkout $checkout)
    {
        $this->generateReceipt($checkout);
        // send email to user and redirect back to attende show page
        $checkout->user->notify(new SendReceiptNotification($checkout));
        return redirect()->route('pos.attendee.show', ['attendeeId' => $checkout->user->attendee_id])->with('success', 'Receipt sent to user.');
    }

    private function generateReceipt(Checkout $checkout)
    {
        CreateReceiptFromCheckoutJob::dispatchSync($checkout);
    }
}
