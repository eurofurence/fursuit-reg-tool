<?php

namespace App\Http\Requests;

use App\Rules\FursuitCatchCodeRule;
use Illuminate\Foundation\Http\FormRequest;

class UserCatchRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'catch_code' => ['required', 'string', 'min:'.config('fcea.fursuit_catch_code_length'), 'max:'.config('fcea.fursuit_catch_code_length'), new FursuitCatchCodeRule],
        ];
    }
}
