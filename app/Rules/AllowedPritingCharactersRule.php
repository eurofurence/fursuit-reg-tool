<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

class AllowedPritingCharactersRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! preg_match('/^[a-zA-Z0-9!"§$%&\/()=?`²³{[\]}@€~#\'*+,\-.;:_<>|°^¨´µ·×÷¹¼½¾¬«»©®™± äöüÄÖÜßéèêëàâçîïôùûÿñ¡¿]+$/u', $value)) {
            $fail(Str::ucfirst($attribute).' can only contain alphanumeric and the following special characters: !"§$%&/()=?`²³{[]}\@€~#\'*+~,-.;:_<>|°^¨´µ·×÷¹²³¼½¾¬«»©®™± äöüÄÖÜßéèêëàâçîïôûùûÿñ¡¿');
        }
    }
}
