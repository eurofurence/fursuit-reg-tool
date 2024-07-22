<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

class AllowedPritingCharactersRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! preg_match('/^[a-zA-Z0-9\s\-\_\.\']+$/u', $value)) {
            $fail(Str::ucfirst($attribute) . ' can only contain letters, numbers, spaces and the following symbols: - . _ ');
        }
    }
}
