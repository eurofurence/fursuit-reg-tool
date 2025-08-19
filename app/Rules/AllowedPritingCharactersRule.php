<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

class AllowedPritingCharactersRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! preg_match('/^[!"§$%&\/()=?`²³{[\]}@€~#\'*+,\-.;:_<>|°^¨´µ·×÷¹¼½¾¬«»©®™± äöüÄÖÜßéèêëàâçîïôûùÿñ¡¿]+$/u', $value)) {
            $fail(Str::ucfirst($attribute).' can only contain the following allowed characters: !"§$%&/()=?`²³{[]}\@€~#\'*+~,-.;:_<>|°^¨´µ·×÷¹²³¼½¾¬«»©®™± äöüÄÖÜßéèêëàâçîïôûùûÿñ¡¿');
        }
    }
}
