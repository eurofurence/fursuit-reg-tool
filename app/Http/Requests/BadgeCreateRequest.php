<?php

namespace App\Http\Requests;

use App\Rules\AlphaNumSpaceRule;
use Illuminate\Foundation\Http\FormRequest;

class BadgeCreateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'species' =>  ['required','string','max:32', new AlphaNumSpaceRule()],
            'name' =>  ['required','string','max:32', new AlphaNumSpaceRule()],
            'image' =>  [
                'required',
                'image',
                'mimes:jpeg,jpg,png',
                'dimensions:min_width=240,min_height=320',
                'max:8192'
            ],
            'catchEmAll' =>  ['required','boolean'],
            'publish' =>  ['required','boolean'],
            'tos' =>  ['required','accepted'],
            'upgrades.doubleSided' => ['required', 'boolean'],
            'upgrades.spareCopy' => ['required', 'boolean'],
        ];
    }
}
