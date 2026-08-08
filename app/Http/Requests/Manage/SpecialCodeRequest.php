<?php

namespace App\Http\Requests\Manage;

use App\Domain\CatchEmAll\Models\SpecialCode;
use App\Domain\CatchEmAll\SpecialActions\ActionField;
use App\Domain\CatchEmAll\SpecialActions\SpecialCodeActionRegistry;
use App\Models\Fursuit\Fursuit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Create and update for a special code.
 *
 * Authorisation happens here rather than in the controller body, because a FormRequest
 * validates before the action runs: gating in the controller would answer an
 * unauthorised write with a 422 about its payload instead of a 403.
 *
 * `catch_url` is deliberately absent. It is a preview built from `code`, never stored
 * (`dehydrated(false)` in the old panel form), so it must not reach the model, which has
 * `$guarded = []`.
 *
 * `constructor_data` is absent for the same reason, and this is the change that removed
 * the last editable JSON from the panel. The request takes `data[<field>]`, one input per
 * key the selected action class declares, validates each on its own path so a bad value is
 * an error on that field, and payload() assembles the object. The old textarea is not
 * accepted under any name: a request that still sends `constructor_data` is ignored, not
 * merged, because nothing outside a declared field may decide the stored shape.
 */
class SpecialCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $specialCode = $this->route('code');

        return $specialCode instanceof SpecialCode
            ? Gate::allows('update', $specialCode)
            : Gate::allows('create', SpecialCode::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $specialCode = $this->route('code');

        $rules = [
            'event_id' => ['required', 'integer', 'exists:events,id'],
            // Not required, matching the form today. The list renders a missing class
            // as an empty cell rather than crashing on it.
            'class_name' => ['nullable', 'string', Rule::in(array_keys(SpecialCodeActionRegistry::options()))],
            'data' => ['nullable', 'array'],
            'code' => [
                'required',
                'string',
                'size:5',
                Rule::unique('special_codes', 'code')->ignore($specialCode),
                /*
                 * Verbatim from the old special-code list: a catch code and a special code are
                 * consumed by the same endpoint, so one may never shadow the other.
                 */
                function (string $attribute, mixed $value, callable $fail) {
                    if (Fursuit::where('catch_code', $value)->exists()) {
                        $fail('This code is already used in Fursuits.');
                    }
                },
            ],
        ];

        /*
         * The rules for the data half come from the class the request is naming. An
         * unregistered class fails the Rule::in above and declares no fields here, so
         * nothing is validated against a schema that does not exist.
         */
        foreach ($this->fields() as $field) {
            $rules['data.'.$field->name] = $field->validationRules();
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [
            'event_id' => 'Event',
            'class_name' => 'Class',
            'data' => 'Action data',
            'code' => 'Code',
        ];

        // So a field error reads "The Amount field must be an integer" rather than naming
        // the data.amount path.
        foreach ($this->fields() as $field) {
            $attributes['data.'.$field->name] = $field->label;
        }

        return $attributes;
    }

    /**
     * The attributes to write, with `constructor_data` assembled from the validated fields.
     *
     * Always an object or null, never anything else. `AbstractSpecialCodeAction::__construct`
     * types its third argument `?object` and GameController catches `\Exception` only, so a
     * stored list or scalar raises a TypeError that escapes the handler and 500s an
     * attendee's scan instead of degrading to its error message. The old rule allowed that
     * because `json` accepts any JSON document; there is no longer a path that lets the
     * request choose the shape at all.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $validated = $this->validated();

        return [
            'event_id' => (int) $validated['event_id'],
            /*
             * `special_codes.class_name` is NOT NULL while the field is optional, so an
             * unselected class has to write something. It writes '', which the list
             * renders as an empty cell exactly like the null rows already in the
             * database; writing null would make saving the form a database error.
             */
            'class_name' => $this->className(),
            'constructor_data' => $this->constructorData($validated['data'] ?? []),
            'code' => $validated['code'],
        ];
    }

    /**
     * @param  array<string, mixed>  $submitted
     */
    private function constructorData(array $submitted): ?object
    {
        $stored = $this->route('code') instanceof SpecialCode
            ? $this->route('code')->constructor_data
            : null;

        /*
         * Keys the current schema does not declare are the operator's data, not ours, so
         * an edit that does not touch them writes them back unchanged: that is what makes
         * a row written before this form existed survive a round trip through it.
         *
         * They are dropped when the class changes, because they described the previous
         * action, and when the stored value was not an object, because there are no keys
         * to keep there and writing the value back would restore the shape that raises a
         * TypeError in the redeem path.
         */
        $sameClass = $this->className() === (string) ($this->route('code')?->class_name ?? '');
        $data = [];

        if ($sameClass) {
            $data = SpecialCodeActionRegistry::undeclaredKeys($this->className(), $stored);
        }

        foreach ($this->fields() as $field) {
            $data[$field->name] = $field->cast($submitted[$field->name] ?? $field->defaultValue());
        }

        /*
         * Nothing to store. A code created without data keeps holding null, which is what
         * every such row holds today, and a class change that dropped the previous
         * action's keys clears the column rather than leaving an empty object behind. A
         * row that already held an object under the same class keeps holding one.
         */
        if ($data === []) {
            return $sameClass && $stored !== null ? (object) [] : null;
        }

        return (object) $data;
    }

    private function className(): string
    {
        $className = $this->input('class_name');

        return is_string($className) ? $className : '';
    }

    /**
     * The declared fields of the class this request names.
     *
     * @return array<int, ActionField>
     */
    private function fields(): array
    {
        return SpecialCodeActionRegistry::fieldsFor($this->className());
    }
}
