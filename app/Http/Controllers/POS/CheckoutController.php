<?php

// Very basic controller just so that it exists for the purpose of showing UI

namespace App\Http\Controllers\POS;

use App\Domain\Checkout\Models\Checkout\Checkout;
use App\Domain\Checkout\Models\Checkout\States\Active;
use App\Domain\Checkout\Models\Checkout\States\Cancelled;
use App\Domain\Checkout\Models\Checkout\States\Finished;
use App\Domain\Checkout\Services\CheckoutService;
use App\Domain\Checkout\Services\FiskalyService;
use App\Http\Controllers\Controller;
use App\Models\Badge\Badge;
use App\Models\Badge\State_Payment\Unpaid;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Inertia;

class CheckoutController extends Controller
{
    public function show(Checkout $checkout)
    {
        // check if machine is allowed to see this checkout
        if ($checkout->machine_id !== auth('machine')->user()->id) {
            abort(403);
        }
        // transaction needs to be in state Active to be shown
        if ($checkout->status->equals(Cancelled::class)) {
            $attendeeId = $checkout->user->eventUser()?->attendee_id;

            return redirect()->route('pos.attendee.show', ['attendeeId' => $attendeeId])->with('error', 'Checkout is cancelled.');
        }
        $transactionData = $this->getTransactionData($checkout);

        if ($transactionData && $transactionData['status'] === 'SUCCESSFUL' && $checkout->status->equals(Active::class)) {
            $checkout->payment_method = 'card';
            $checkout->save();

            // Update Fiskaly transaction to FINISHED state to get end signature
            $fiskalyService = new FiskalyService;
            $fiskalyService->finishTransaction($checkout);

            // Transition to finished state after Fiskaly update
            $checkout->status->transitionTo(Finished::class);
        }

        return Inertia::render('POS/Checkout/Show', [
            // The payable comes along so the price override dialog can name the
            // fursuit rather than repeat "Fursuit Badge" once per line, and so
            // the transaction panel can show the artwork the clerk matches
            // against the card they are about to hand over.
            'checkout' => $checkout->load('items.payable.fursuit.species'),
            'transaction' => $transactionData ?? null,
        ]);
    }

    public function payWithCash(Checkout $checkout)
    {
        $checkout->payment_method = 'cash';
        $checkout->save();

        // Update Fiskaly transaction to FINISHED state to get end signature
        $fiskalyService = new FiskalyService;
        $fiskalyService->finishTransaction($checkout);

        // Transition to finished state after Fiskaly update
        $checkout->status->transitionTo(Finished::class);

        return redirect()->route('pos.checkout.show', ['checkout' => $checkout->id]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'badge_ids.*' => 'nullable|int',
            'user_id' => 'required|int|exists:users,id',
        ]);

        if (empty($data['badge_ids'])) {
            $data['badge_ids'] = Badge::whereHas('fursuit.user', function ($query) use ($data) {
                $query->where('id', $data['user_id']);
            })->where('status_payment', Unpaid::$name)->pluck('id')->toArray();
        } else {

            $data['badge_ids'] = Badge::whereHas('fursuit.user', function ($query) use ($data) {
                $query->where('id', $data['user_id']);
            })->where('status_payment', Unpaid::$name)
                ->whereIn('id', $data['badge_ids'])
                ->pluck('id')->toArray();
        }

        $badges = Badge::whereIn('id', $data['badge_ids'])->get();
        if ($badges->isEmpty()) {
            return redirect()->back()->with(['error' => 'No badges found']);
        }

        $checkout = (new CheckoutService)->create($badges, $data['user_id']);

        return redirect()->route('pos.checkout.show', ['checkout' => $checkout->id]);

    }

    public function destroy(Checkout $checkout)
    {
        // Cancel the Fiskaly transaction to get proper end signature
        $fiskalyService = new FiskalyService;
        $fiskalyService->cancelTransaction($checkout);

        // Transition to cancelled state after Fiskaly update
        $checkout->status->transitionTo(Cancelled::class);

        $attendeeId = $checkout->user->eventUser()?->attendee_id;

        return redirect()->route('pos.attendee.show', ['attendeeId' => $attendeeId]);
    }

    public function startCardPayment(Checkout $checkout)
    {
        $reader = $checkout->machine->sumupReader;
        $checkout->payment_method = 'card';
        $uuid = Str::uuid();

        try {
            $response = Http::sumup()->post('/v0.1/merchants/'.config('services.sumup.merchant_code').'/readers/'.$reader->remote_id.'/checkout', [
                'affiliate' => [
                    'app_id' => config('services.sumup.app_id'),
                    'foreign_transaction_id' => $uuid,
                    'key' => config('services.sumup.affiliate_key'),
                ],
                'description' => 'Fursuit Badges Payment',
                'card_type' => 'debit',
                'return_url' => 'https://test.de',
                'total_amount' => [
                    'currency' => 'EUR',
                    'value' => $checkout->total,
                    'minor_unit' => 2,
                ],
            ]);

            // Check for specific reader offline error
            if ($response->status() === 422) {
                $errorData = $response->json();
                if (isset($errorData['errors']['type']) && $errorData['errors']['type'] === 'READER_OFFLINE') {
                    return redirect()->route('pos.checkout.show', ['checkout' => $checkout->id])
                        ->with('error', 'Card reader is offline. Please check the connection and try again.');
                }
            }

            // Throw for other non-successful responses
            $response->throw();

            $data = $response->json('data');
            $checkout->payment_method_remote_id = $uuid;
            $checkout->save();

        } catch (RequestException|ConnectionException $e) {
            return redirect()->route('pos.checkout.show', ['checkout' => $checkout->id])
                ->with('error', 'Card payment failed: Unable to connect to payment system.');
        }

        return redirect()->route('pos.checkout.show', ['checkout' => $checkout->id]);
    }

    /**
     * @return array|mixed
     *
     * @throws ConnectionException
     */
    public function getTransactionData(Checkout $checkout): mixed
    {
        $transactionData = null;
        // Get the transaction

        if ($checkout->payment_method_remote_id) {
            $response = Http::sumup()->get('/v0.1/me/transactions', [
                'foreign_transaction_id' => $checkout->payment_method_remote_id,
            ]);
            $transactionData = $response->json();
            if (isset($transactionData['error_code']) && $transactionData['error_code'] === 'NOT_FOUND') {
                sleep(2);

                return $this->getTransactionData($checkout);
            }
            $response->throw();
        }

        return $transactionData;
    }
}
