<?php

namespace App\Http\Controllers\POS;

use App\Domain\Checkout\Models\Checkout\Checkout;
use App\Domain\Checkout\Models\Checkout\States\Active;
use App\Domain\Checkout\Services\CheckoutService;
use App\Http\Controllers\Controller;
use App\Models\Badge\Badge;
use App\Models\Badge\State_Payment\Unpaid;
use App\Models\Species;
use App\Rules\AllowedPritingCharactersRule;
use App\Services\ManagerApprovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fixing an order at the desk.
 *
 * Two different corrections with two different bars:
 *
 * - The details (name, species, print options, gallery flags) are typos. Any
 *   cashier fixes those, because the attendee is standing there saying what is
 *   wrong and a second signature buys nothing.
 * - The price is money. It needs a manager, either because one is signed in at
 *   the till or because one approves with their PIN or RFID tag.
 *
 * A price change invalidates a live transaction: the Fiskaly receipt is signed
 * against a total, so the open checkout is cancelled with an end signature and
 * reopened at the new price rather than edited in place.
 *
 * Unlike the attendee-facing edit, this one does not send the fursuit back to
 * pending review. The badge is being handed over now; a staff member who just
 * retyped a name has already reviewed it.
 */
class BadgeEditController extends Controller
{
    /**
     * A badge is five euros. The ceiling is a fat-finger guard, not policy - it
     * exists so a slipped keypress cannot open a 500 euro card transaction.
     */
    private const MAX_TOTAL_CENTS = 50000;

    private const TAX_RATE = 0.19;

    public function update(Request $request, Badge $badge): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:32', new AllowedPritingCharactersRule],
            'species' => ['required', 'string', 'max:32', new AllowedPritingCharactersRule],
            'dual_side_print' => ['required', 'boolean'],
            'published' => ['required', 'boolean'],
            'catch_em_all' => ['required', 'boolean'],
        ]);

        DB::transaction(function () use ($badge, $data) {
            $fursuit = $badge->fursuit;

            $fursuit->fill([
                'name' => $data['name'],
                'species_id' => Species::firstOrCreate(
                    ['name' => $data['species']],
                    ['name' => $data['species'], 'checked' => false],
                )->id,
                'published' => $data['published'],
                'catch_em_all' => $data['catch_em_all'],
            ]);
            $fursuit->save();

            $badge->dual_side_print = $data['dual_side_print'];
            $badge->save();
        });

        activity()
            ->performedOn($badge)
            ->causedBy(auth('machine-user')->user())
            ->log('Badge edited at POS');

        return redirect()->back()->with('success', 'Badge updated.');
    }

    /**
     * Override what one or more badges cost.
     *
     * Prices arrive as `prices[badge_id] = cents` so the payment screen can
     * correct every line of a transaction behind a single approval, and the
     * attendee screen can correct one badge with the same endpoint.
     */
    public function updatePrices(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'prices' => ['required', 'array', 'min:1'],
            'prices.*' => ['required', 'integer', 'min:0', 'max:'.self::MAX_TOTAL_CENTS],
            'manager_code' => ['nullable', 'string'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $manager = ManagerApprovalService::approve($data['manager_code'] ?? null);

        $badges = Badge::whereIn('id', array_keys($data['prices']))->get();

        if ($badges->isEmpty()) {
            throw ValidationException::withMessages(['prices' => 'No badges found.']);
        }

        // A paid badge has a signed receipt behind it; changing its price would
        // make the receipt a lie. Refunds are a different, deliberate action.
        $paid = $badges->reject(fn (Badge $badge) => $badge->status_payment->equals(Unpaid::class));

        if ($paid->isNotEmpty()) {
            throw ValidationException::withMessages([
                'prices' => 'Already paid: '.$paid->pluck('custom_id')->implode(', ')
                    .'. Paid badges cannot be repriced.',
            ]);
        }

        DB::transaction(function () use ($badges, $data, $manager) {
            foreach ($badges as $badge) {
                $total = (int) $data['prices'][$badge->id];
                $was = (int) $badge->total;

                $badge->total = $total;
                $badge->subtotal = (int) round($total / (1 + self::TAX_RATE));
                $badge->tax = $total - $badge->subtotal;
                $badge->tax_rate = self::TAX_RATE;
                $badge->save();

                activity()
                    ->performedOn($badge)
                    ->causedBy(auth('machine-user')->user())
                    ->withProperties([
                        'from' => $was,
                        'to' => $total,
                        'approved_by_staff_id' => $manager->id,
                        'approved_by' => $manager->name,
                        'reason' => $data['reason'] ?? null,
                    ])
                    ->log('Badge price overridden');
            }
        });

        $checkout = $this->activeCheckoutFor($badges->pluck('id'));

        if ($checkout) {
            $rebuilt = (new CheckoutService)->rebuild($checkout);

            return redirect()
                ->route('pos.checkout.show', ['checkout' => $rebuilt->id])
                ->with('success', 'Price overridden by '.$manager->name.'. Transaction reopened.');
        }

        return redirect()->back()->with('success', 'Price overridden by '.$manager->name.'.');
    }

    /**
     * The open transaction on this till holding any of these badges, if there is
     * one. Scoped to the machine so a repricing here never cancels the sale
     * running on the till next door.
     */
    private function activeCheckoutFor($badgeIds): ?Checkout
    {
        return Checkout::where('machine_id', auth('machine')->user()->id)
            ->where('status', Active::$name)
            ->whereHas('items', function ($query) use ($badgeIds) {
                $query->where('payable_type', Badge::class)
                    ->whereIn('payable_id', $badgeIds);
            })
            ->latest('id')
            ->first();
    }
}
