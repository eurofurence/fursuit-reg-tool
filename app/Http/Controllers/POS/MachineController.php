<?php

namespace App\Http\Controllers\POS;

use App\Http\Controllers\Controller;
use App\Models\Machine;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MachineController extends Controller
{
    public function updateTimeout(Request $request, Machine $machine)
    {
        $request->validate([
            'auto_logout_timeout' => [
                'nullable',
                'integer',
                'min:30',
                'max:1800',
                Rule::in([30, 60, 120, 180, 300, 900, 1800, null]),
            ],
        ]);

        $machine->update([
            'auto_logout_timeout' => $request->auto_logout_timeout,
        ]);

        return back()->with('success', 'Auto logout timeout updated successfully.');
    }
}
