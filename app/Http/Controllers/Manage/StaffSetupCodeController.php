<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Support\Manage\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The `Generate` button on the staff form's Setup Code field. It proposes a code; it does
 * not write one.
 *
 * That is the whole point of this controller existing (plan 2.10 #23, audit 36). The
 * Filament suffix action called `$record->generateSetupCode()`, and `Staff::
 * generateSetupCode()` ends with `$this->update(['setup_code' => $code])` - so pressing
 * Generate on an existing member wrote a new setup code to the database immediately,
 * before the form was submitted. Generate and then navigate away and the member is
 * carrying a code the operator never committed, with the previous one gone. A setup code
 * is a one-shot POS login credential, so that is a live credential rotated by a button
 * press the operator did not confirm.
 *
 * Here the code is only ever flashed back to the form. It reaches the database when, and
 * only when, the operator saves. Nothing in this file calls a model write.
 */
class StaffSetupCodeController extends Controller
{
    /**
     * The alphabet Staff::generateSetupCode() draws from, kept identical so a code
     * proposed here is indistinguishable from one the POS setup flow has always seen.
     */
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    /**
     * How many candidates to draw before giving up. The pool is 36^6, so a collision is a
     * broken assumption rather than bad luck; the Filament loop had no bound at all.
     */
    private const ATTEMPTS = 20;

    /**
     * `$staff` is null on the create screen, where there is no record yet. The proposal
     * does not depend on the record either way - it is a code no other member holds - so
     * the parameter exists only to authorize the right ability.
     */
    public function store(?Staff $staff = null): RedirectResponse
    {
        $staff instanceof Staff
            ? Gate::authorize('update', $staff)
            : Gate::authorize('create', Staff::class);

        $code = $this->propose();

        if ($code === null) {
            Toast::flashDanger(
                'No setup code generated',
                'Could not find an unused code. Try again.'
            );

            return back();
        }

        // Flashed, not saved. The form picks it up as a prop and the save persists it.
        session()->flash('admin.staff.generated_setup_code', $code);

        return back();
    }

    /**
     * A six-character code no staff member currently holds.
     *
     * `str_shuffle` over the alphabet is what Staff::generateSetupCode() does, so the
     * shape of a generated code does not change. It is not a security primitive and does
     * not need to be: the code is short-lived, single-use, and only ever handed to a
     * member in person.
     */
    private function propose(): ?string
    {
        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $code = strtoupper(substr(str_shuffle(self::ALPHABET), 0, 6));

            if (! Staff::where('setup_code', $code)->exists()) {
                return $code;
            }
        }

        return null;
    }
}
