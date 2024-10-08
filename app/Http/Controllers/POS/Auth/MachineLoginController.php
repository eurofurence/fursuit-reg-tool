<?php

namespace App\Http\Controllers\POS\Auth;

use App\Http\Controllers\Controller;
use App\Models\Machine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;

class MachineLoginController extends Controller
{
    public function __invoke(Request $request)
    {
        $data = $request->validate([
            'machine_id' => 'required|integer',
        ]);

        $machine = Machine::findOrFail($data['machine_id']);
        Auth::guard('machine')->login($machine, true);

        return Redirect::route('pos.auth.user.select');
    }
}
