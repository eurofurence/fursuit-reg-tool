<?php

namespace App\Http\Requests\Manage;

use App\Models\RfidTag;
use App\Models\Staff;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Create and update for the RFID tags nested under a staff member.
 *
 * The rules are the relation manager's form schema verbatim: `content` required and
 * unique on `rfid_tags.content` ignoring the record being edited, `name` optional, and
 * the `is_active` toggle.
 *
 * The asymmetry with POS self-service is left alone. MachineUserAuthController
 * enforces `min:8`, `max:20` and digits only on a tag an attendee registers at the till;
 * this form accepts any string up to 255, so an admin can enter a tag whose reader output
 * the POS validator would refuse. Tightening it here is a behaviour change nobody asked
 * for and would lock admins out of correcting a tag that already exists, so parity wins
 * and the divergence is recorded in docs/admin/parity-checklist.md instead.
 */
class RfidTagRequest extends FormRequest
{
    /**
     * Both abilities are asked, because both are real: the tag has its own policy now
     *, and the staff member it hangs off has to be writable too. Read
     * RfidTagPolicy before loosening either - a tag value is a POS credential.
     */
    public function authorize(): bool
    {
        $staff = $this->route('staff');

        if (! $staff instanceof Staff || Gate::denies('update', $staff)) {
            return false;
        }

        $tag = $this->route('rfidTag');

        return $tag instanceof RfidTag
            ? Gate::allows('update', $tag)
            : Gate::allows('create', RfidTag::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $tag = $this->route('rfidTag');
        $id = $tag instanceof RfidTag ? $tag->getKey() : null;

        return [
            'content' => [
                'required',
                'string',
                'max:255',
                Rule::unique('rfid_tags', 'content')->ignore($id),
            ],
            'name' => ['nullable', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * The relation manager's labels, so a message names the field the way the form does.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'content' => 'RFID Code',
            'name' => 'Tag Name',
            'is_active' => 'Active',
        ];
    }
}
