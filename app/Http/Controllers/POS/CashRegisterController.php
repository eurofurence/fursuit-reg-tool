<?php

namespace App\Http\Controllers\POS;

use App\Http\Controllers\Controller;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class CashRegisterController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('POS/CashRegister/Show', [
            'backToRoute' => 'pos.dashboard',
            'wallet' => Auth::guard('machine')->user()->wallet,
        ]);
    }

    public function moneyAddForm(): Response
    {
        return Inertia::render('POS/CashRegister/AddMoney', [
            'backToRoute' => 'pos.wallet.show',
        ]);
    }

    public function moneyRemoveForm(): Response
    {
        return Inertia::render('POS/CashRegister/RemoveMoney', [
            'balance' => Auth::guard('machine')->user()->wallet->balance,
            'backToRoute' => 'pos.wallet.show',
        ]);
    }

    public function moneyAdd(Request $request): RedirectResponse
    {

        $deposit = Auth::guard('machine')->user()->wallet->deposit(intval($request->get('amount')));

        if (! $deposit) {
            return redirect()->back()->withErrors(['amount' => 'Could not add amount to cash register']);
        } else {
            return redirect()->route('pos.wallet.show');
        }
    }

    public function moneyRemove(Request $request): RedirectResponse
    {
        try {
            Auth::guard('machine')->user()->wallet->withdraw(intval($request->get('amount')));
        } catch (Exception $ex) {
            return redirect()->back()->withErrors(['amount' => 'Could not withdraw amount to cash register']);
        }

        return redirect()->route('pos.wallet.show');
    }
}
