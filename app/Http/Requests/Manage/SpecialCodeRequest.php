<?php

namespace App\Http\Requests\Manage;

use App\Domain\CatchEmAll\Models\SpecialCode;
use App\Http\Controllers\Manage\SpecialCodeController;
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
 * (`dehydrated(false)` in the Filament form), so it must not reach the model, which has
 * `$guarded = []`.
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

        return [
            'event_id' => ['required', 'integer', 'exists:events,id'],
            // Not required, matching the form today. The list renders a missing class
            // as an empty cell rather than crashing on it (audit 30).
            'class_name' => ['nullable', 'string', Rule::in(array_keys(SpecialCodeController::CLASS_OPTIONS))],
            'constructor_data' => [
                'nullable',
                'json',
                /*
                 * `json` alone accepts any JSON document, so `[1,2,3]` or `5` passes it.
                 * The stored value is handed straight to
                 * AbstractSpecialCodeAction::__construct, whose third argument is typed
                 * `?object`, and the redeem path catches \Exception only, so a TypeError
                 * (an \Error) escapes GameController's handler and 500s an attendee's
                 * scan instead of degrading to its error message. The field only became
                 * editable with plan 2.10 #39, so this is the first time anything could
                 * write a non-object here.
                 */
                function (string $attribute, mixed $value, callable $fail) {
                    if (! is_string($value)) {
                        return;
                    }

                    $decoded = json_decode($value);

                    // Malformed JSON is the `json` rule's message to give, not this one's.
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        return;
                    }

                    if (! is_object($decoded)) {
                        $fail('The :attribute must be a JSON object.');
                    }
                },
            ],
            'code' => [
                'required',
                'string',
                'size:5',
                Rule::unique('special_codes', 'code')->ignore($specialCode),
                /*
                 * Verbatim from SpecialCodeResource: a catch code and a special code are
                 * consumed by the same endpoint, so one may never shadow the other.
                 */
                function (string $attribute, mixed $value, callable $fail) {
                    if (Fursuit::where('catch_code', $value)->exists()) {
                        $fail('This code is already used in Fursuits.');
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'event_id' => 'Event',
            'class_name' => 'Class',
            'constructor_data' => 'Constructor Data',
            'code' => 'Code',
        ];
    }

    /**
     * The attributes to write, with `constructor_data` decoded.
     *
     * The model casts `constructor_data` to `object`, so assigning the textarea's raw
     * JSON string would json_encode the string itself and store a quoted blob that
     * `createActionInstance()` then hands the action class as a string, not the object
     * its constructor is typed for. The field is disabled today so nothing has ever been
     * written through it; it becomes editable with plan 2.10 #39, which is exactly when
     * this has to be right.
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
            'class_name' => $validated['class_name'] ?? '',
            'constructor_data' => isset($validated['constructor_data'])
                ? json_decode($validated['constructor_data'])
                : null,
            'code' => $validated['code'],
        ];
    }
}
