<?php

// Very basic controller just so that it exists for the purpose of showing UI

namespace App\Http\Controllers\POS;

use App\Domain\Checkout\Models\Checkout\Checkout;
use App\Domain\Checkout\Models\Checkout\States\Active;
use App\Domain\Checkout\Models\Checkout\States\Cancelled;
use App\Domain\Checkout\Models\Checkout\States\Finished;
use App\Domain\Checkout\Services\FiskalyService;
use App\Http\Controllers\Controller;
use App\Models\Badge\Badge;
use App\Models\Badge\State_Payment\Unpaid;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

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
            return redirect()->route('pos.attendee.show', ['attendeeId' => $checkout->user->attendee_id])->with('error', 'Checkout is cancelled.');
        }
        $transactionData = $this->getTransactionData($checkout);

        if ($transactionData && $transactionData['status'] === 'SUCCESSFUL' && $checkout->status->equals(Active::class)) {
            $checkout->payment_method = 'card';
            $checkout->save();
            $checkout->status->transitionTo(Finished::class);
        }


        return Inertia::render('POS/Checkout/Show', [
            'checkout' => $checkout->load('items'),
            'transaction' => $transactionData ?? null,
        ]);
    }

    public function payWithCash(Checkout $checkout)
    {
        $checkout->payment_method = 'cash';
        $checkout->save();
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

        $checkout = DB::transaction(function () use ($badges, $data) {
            $total = $badges->sum('total');
            $subtotal = $badges->sum('subtotal');
            $tax = $badges->sum('tax');

            // Create Checkout
            $checkout = Checkout::create([
                'remote_id' => Str::uuid(),
                'remote_rev_count' => 1,
                'status' => 'ACTIVE',
                'user_id' => $data['user_id'],
                'cashier_id' => auth('machine-user')->id(),
                'machine_id' => auth('machine')->user()->id,
                'total' => $total,
                'tax' => $tax,
                'subtotal' => $subtotal,
                'fiskaly_data' => [],
            ]);

            foreach ($badges as $badge) {
                $checkout->items()->create([
                    'payable_type' => Badge::class,
                    'payable_id' => $badge->id,
                    //
                    'name' => $this->generateName($badge),
                    'description' => $this->generateDescription($badge),
                    //
                    'total' => $badge->total,
                    'tax' => $badge->tax,
                    'subtotal' => $badge->subtotal,
                ]);
            }
            return $checkout;
        });

        // Fiskaly
        $fiskalyService = new FiskalyService();
        $fiskalyService->updateOrCreateTransaction($checkout);

        return redirect()->route('pos.checkout.show', ['checkout' => $checkout->id]);

    }

    public function destroy(Checkout $checkout)
    {
        $checkout->status->transitionTo(Cancelled::class);
        return redirect()->route('pos.attendee.show', ['attendeeId' => $checkout->user->attendee_id]);
    }


    public function startCardPayment(Checkout $checkout)
    {
        $reader = $checkout->machine->sumupReader;
        $checkout->payment_method = 'card';
        $uuid = Str::uuid();
        $response = Http::sumup()->post("/v0.1/merchants/" . config('services.sumup.merchant_code') . "/readers/" . $reader->remote_id . '/checkout', [
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
            ]
        ])->throw();
        $data = $response->json('data');
        $checkout->payment_method_remote_id = $uuid;
        $checkout->save();
        return redirect()->route('pos.checkout.show', ['checkout' => $checkout->id]);
    }

    private function generateDescription(Badge $badge): array
    {
        $features = [];
        if ($badge->dual_side_print) {
            $features[] = 'Double Sided Print';
        }
        if ($badge->extra_copy_of) {
            $features[] = 'Extra Copy';
        }
        return $features;
    }

    private function generateName(Badge $badge): string
    {
        return 'Fursuit Badge';
    }

    /**
     * @param Checkout $checkout
     * @return array|mixed
     * @throws \Illuminate\Http\Client\ConnectionException
     */
    public function getTransactionData(Checkout $checkout): mixed
    {
        $transactionData = null;
        // Get the transaction

        if ($checkout->payment_method_remote_id) {
            $response = Http::sumup()->get("/v0.1/me/transactions", [
                "foreign_transaction_id" => $checkout->payment_method_remote_id,
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
