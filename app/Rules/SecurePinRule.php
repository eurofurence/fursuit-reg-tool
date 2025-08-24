<?php

namespace App\Rules;

use App\Models\Staff;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SecurePinRule implements ValidationRule
{
    protected ?int $excludeStaffId;

    public function __construct(?int $excludeStaffId = null)
    {
        $this->excludeStaffId = $excludeStaffId;
    }

    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $errors = Staff::validatePinStrength($value, $this->excludeStaffId);

        if (! empty($errors)) {
            foreach ($errors as $error) {
                $fail($error);
            }
        }
    }
}
