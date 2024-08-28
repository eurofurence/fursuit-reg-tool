<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

class FursuitCatchCodeRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! preg_match('/^[a-zA-Z0-9]+$/u', $value)) {
            $fail(Str::ucfirst($attribute) . ' can only contain letters and numbers');
        }
    }
}
