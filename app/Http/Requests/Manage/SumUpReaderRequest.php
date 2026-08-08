<?php

namespace App\Http\Requests\Manage;

use App\Models\SumUpReader;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Create and update for /admin/sumup-readers.
 *
 * The rules are SumUpReaderResource's form schema (audit 4.11) with one field gone and
 * one rule relaxed on update.
 *
 * Gone: `remote_id`. Filament declared it `->readOnly()`, which is a client-side attribute
 * only; the value still round-trips through the request and `$guarded = []` on the model,
 * so a crafted POST rewrites which SumUp-side reader this row is bound to (plan 2.10 #17,
 * audit landmine 12). It is not in the rules and not in the payload, so the binding can
 * only change through a SumUp-side sync.
 *
 * Relaxed: `paring_code` is required on create and optional on update. The pairing code is
 * a payment terminal credential and is never shipped to the browser (plan 2.10 #16), so
 * the edit form opens with an empty field; an empty field there means "keep the stored
 * code" rather than "blank the credential", and the controller drops the key. On create
 * there is nothing to keep, so it stays required exactly as Filament had it.
 */
class SumUpReaderRequest extends FormRequest
{
    /**
     * Authorized here rather than in the controller so an operator who may not write never
     * gets validation feedback on a form they cannot submit.
     */
    public function authorize(): bool
    {
        $reader = $this->route('reader');

        return $reader instanceof SumUpReader
            ? Gate::allows('update', $reader)
            : Gate::allows('create', SumUpReader::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $editing = $this->route('reader') instanceof SumUpReader;

        return [
            'name' => ['required', 'string', 'max:255'],
            'paring_code' => [$editing ? 'nullable' : 'required', 'string', 'max:255'],
        ];
    }

    /**
     * Filament's auto labels, so a validation message names the field the way the form
     * does. The column-name typo is kept everywhere: it is baked into
     * 2024_09_14_224516_create_sumup_readers_table and into the POS code paths that read
     * it, so "fixing" the spelling breaks them (plan 2.10 #16).
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'paring_code' => 'paring code',
        ];
    }
}
