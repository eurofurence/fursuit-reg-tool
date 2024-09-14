<?php

// Very basic controller just so that it exists for the purpose of showing UI

namespace App\Http\Controllers\POS;

use App\Http\Controllers\Controller;
use App\Models\Badge\Badge;
use App\Models\Checkout\Checkout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CheckoutController extends Controller
{
    public function show(Checkout $checkout):  Response
    {
        return Inertia::render('POS/Checkout/Show', [
            'checkout' => $checkout->load('items'),
        ]);
    }

    /**
     * Start a new checkout
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'badge_ids.*' => 'nullable|int',
            'user_id' => 'required|int|exists:users,id',
        ]);

        if(empty($data['badge_ids'])) {
            $data['badge_ids'] = Badge::whereHas('fursuit.user', function($query) use ($data) {
                $query->where('id', $data['user_id']);
            })->where('status', 'unpaid')->pluck('id')->toArray();
        }

        $checkout = DB::transaction(function() use ($data) {
            $badges = Badge::whereIn('id', $data['badge_ids'])->get();
            $total = $badges->sum('total');
            $subtotal = $badges->sum('subtotal');
            $tax = $badges->sum('tax');

            // Create Checkout
            $checkout = Checkout::create([
                'status' => 'open',
                'user_id' => $data['user_id'],
                'cashier_id' => auth('machine-user')->id(),
                'total' => $total,
                'tax' => $tax,
                'subtotal' => $subtotal,
                'fiskaly_data' => [],
            ]);
            foreach($badges as $badge) {
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

        return redirect()->route('pos.checkout.show', ['checkout' => $checkout->id]);

    }

    private function generateDescription(Badge $badge): array
    {
        $features = [];
        if($badge->dual_side_print) {
            $features[] = 'Double Sided Print';
        }
        if($badge->extra_copy_of) {
            $features[] = 'Extra Copy';
        }
        return $features;
    }

    private function generateName(Badge $badge): string
    {
        return 'Fursuit Badge';
    }
}
