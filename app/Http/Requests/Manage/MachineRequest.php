<?php

namespace App\Http\Requests\Manage;

use App\Models\Machine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Create and update for /admin/machines.
 *
 * The rules are MachineResource's form schema (audit 4.6): four fields, nothing else.
 * That matters more here than on most modules because `Machine::$guarded = []`, so any
 * key that survives validation reaches the row. `archived_at` is not in this list, which
 * is why archiving is two endpoints of its own rather than a field on the form.
 *
 * The two foreign keys are validated against their tables. Filament's Select carried the
 * relation and so could only ever offer an existing id; a hand-rolled POST cannot, and a
 * machine pointed at a TSE client that does not exist signs no checkouts at all.
 */
class MachineRequest extends FormRequest
{
    /**
     * Authorized here rather than in the controller so an operator who may not write
     * never gets validation feedback on a form they cannot submit.
     */
    public function authorize(): bool
    {
        $machine = $this->route('machine');

        return $machine instanceof Machine
            ? Gate::allows('update', $machine)
            : Gate::allows('create', Machine::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'sumup_reader_id' => ['nullable', 'integer', Rule::exists('sumup_readers', 'id')],
            // Filament's Checkbox: present and boolean. `required` accepts false, which
            // is what an unticked box sends.
            'should_discover_printers' => ['required', 'boolean'],
        ];
    }

    /**
     * Filament's own labels, so a validation message names the field the way the form
     * does.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'sumup_reader_id' => 'SumUp Reader',
            'should_discover_printers' => 'should discover printers',
        ];
    }
}
