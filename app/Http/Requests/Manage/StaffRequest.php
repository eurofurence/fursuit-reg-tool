<?php

namespace App\Http\Requests\Manage;

use App\Http\Controllers\Manage\StaffController;
use App\Models\Staff;
use App\Rules\SecurePinRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Create and update for /admin/staff.
 *
 * The fields are StaffResource's form schema (audit 4.10) with three fixes the plan
 * mandates, all of them about the same thing: this form cannot be saved twice today.
 *
 *  - `SecurePinRule` is handed the record id (plan 2.10 #21, audit 34). Filament
 *    constructed it as `new SecurePinRule` with no argument, so
 *    Staff::validatePinStrength() ran its uniqueness check across every row *including
 *    the one being edited*: opening a staff member with a PIN and pressing Save without
 *    changing anything failed with `This PIN is not secure enough. Please choose a
 *    different PIN.`. Same shape as the unique-ignore trap, and the same fix.
 *  - A blank `setup_code` dehydrates to null, not `''` (plan 2.10 #22, audit 35).
 *    `strtoupper($state ?? '')` wrote the empty string into a column carrying a UNIQUE
 *    index, so the first blank saved fine and the second blew up with SQL 1062.
 *  - the PIN never leaves the server, so it never comes back either. The edit form is
 *    handed `StaffController::PIN_UNCHANGED` instead, and an untouched field submits that
 *    sentinel, which is dropped here before validation (plan 2.10 #66). Emptying the
 *    field still clears the PIN, which is what the helper text says it does.
 *  - `setup_code` is also checked for uniqueness against other rows. The index has always
 *    been there and the form never validated it, so a collision surfaced as SQL 1062
 *    rather than a field error. Nobody hit it on a non-blank code because the blank case
 *    got there first. Same reasoning as the uniqueness UserRequest added.
 *
 * The PIN is validated with `digits:6` rather than Filament's `numeric` + `length(6)`.
 * Both express "exactly six digits", but `numeric` made the client coerce the value to a
 * number and drop a leading zero (audit 121), and under a `numeric` rule Laravel's size
 * comparisons measure the value rather than its length. `digits:6` measures the string,
 * so `012345` survives the round trip.
 *
 * The duplicate-PIN message stays deliberately vague. Staff::validatePinStrength reports
 * a PIN that is already in use as `This PIN is not secure enough...` with the source
 * comment "don't reveal that another user has this PIN for security" (audit 121); a
 * clearer message would leak that some other member holds it.
 */
class StaffRequest extends FormRequest
{
    /**
     * Authorized here rather than in the controller so an operator who may not write
     * never gets validation feedback on a form they cannot submit.
     */
    public function authorize(): bool
    {
        $staff = $this->route('staff');

        return $staff instanceof Staff
            ? Gate::allows('update', $staff)
            : Gate::allows('create', Staff::class);
    }

    /**
     * Filament's `->mutateDehydratedStateUsing(fn ($state) => strtoupper($state ?? ''))`,
     * with the empty string it produced replaced by null.
     *
     * Done before validation rather than in the controller so the uniqueness check below
     * sees the value that is actually stored: `abc123` and `ABC123` are the same setup
     * code once saved, and only one of them may exist.
     */
    protected function prepareForValidation(): void
    {
        $this->dropUnchangedPin();

        if (! $this->has('setup_code')) {
            return;
        }

        $code = trim((string) $this->input('setup_code'));

        $this->merge([
            'setup_code' => $code === '' ? null : strtoupper($code),
        ]);
    }

    /**
     * Remove the PIN field entirely when it still carries the sentinel the edit form was
     * handed, so `validated()` does not mention it and the update leaves the column alone.
     *
     * Removed rather than nulled: null is what the field means when the operator empties
     * it, and that clears the PIN. `replace()` rather than `merge()`, because merge cannot
     * take a key away and the input source is JSON on an Inertia visit.
     */
    private function dropUnchangedPin(): void
    {
        if ($this->input('pin_code') !== StaffController::PIN_UNCHANGED) {
            return;
        }

        $input = $this->all();

        unset($input['pin_code']);

        $this->replace($input);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $staff = $this->route('staff');
        $id = $staff instanceof Staff ? $staff->getKey() : null;

        return [
            'name' => ['required', 'string', 'max:255'],
            // The record id is what stops an unchanged PIN failing against itself.
            'pin_code' => ['nullable', 'digits:6', new SecurePinRule($id)],
            'setup_code' => [
                'nullable',
                'string',
                'size:6',
                Rule::unique('staff', 'setup_code')->ignore($id),
            ],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * Filament's labels, so a validation message names the field the way the form does.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'pin_code' => 'PIN Code',
            'setup_code' => 'Setup Code',
            'is_active' => 'Active',
        ];
    }
}
