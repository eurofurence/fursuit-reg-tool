<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BadgeUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'species' =>  ['required','string'],
            'name' =>  ['required','string','max:32'],
            'image' =>  [
                'nullable',
                'image',
                'mimes:jpeg,jpg,png',
                'dimensions:min_width=240,min_height=320',
                'max:2048'
            ],
            'catchEmAll' =>  ['required','boolean'],
            'publish' =>  ['required','boolean'],
            'upgrades.doubleSided' => ['required', 'boolean'],
        ];
    }
}