<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Persists which columns an operator has hidden, per table, for the session.
 *
 * 17 of the panel's 23 toggleable columns are hidden by default today and Filament
 * remembered the operator's choice; losing that on every navigation is one of the
 * easiest regressions to ship in a rewrite.
 *
 * The session is already per user, so nothing here needs a user id. The route
 * constrains {table} to a slug so the key cannot be steered into another namespace.
 */
class TableColumnController extends Controller
{
    public function update(Request $request, string $table): RedirectResponse
    {
        $validated = $request->validate([
            'hidden' => ['present', 'array'],
            'hidden.*' => ['string'],
        ]);

        session()->put("manage.table.{$table}.hidden", array_values($validated['hidden']));

        return back();
    }
}
