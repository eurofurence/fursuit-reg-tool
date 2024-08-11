<?php

namespace App\Http\Requests\API;

use App\Models\Fursuit\States\Approved;
use App\Models\Fursuit\States\Pending;
use App\Models\Fursuit\States\Rejected;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FursuitSearchRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     * Validates name, red_id and status in requests to specific rules
     */
    public function rules(): array
    {
        return [
            'name' => ['max:64', 'string', 'nullable'],
            'reg_id' => ['integer', 'nullable', 'exists:users,attendee_id'],
            'status' => ['string', 'nullable', Rule::in([Pending::$name, Approved::$name, Rejected::$name, 'any'])],
        ];
    }
}
