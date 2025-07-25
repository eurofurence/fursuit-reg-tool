<?php

namespace App\Http\Requests;

use App\Rules\AllowedPritingCharactersRule;
use Illuminate\Foundation\Http\FormRequest;

class BadgeUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'species' =>  ['required','string','max:32', new AllowedPritingCharactersRule()],
            'name' =>  ['required','string','max:32', new AllowedPritingCharactersRule()],
            'image' =>  [
                'nullable',
                'image',
                'mimes:jpeg,jpg,png',
                'dimensions:min_width=240,min_height=320',
                'max:8192'
            ],
            'catchEmAll' =>  ['required','boolean'],
            'publish' =>  ['required','boolean'],
        ];
    }
}
