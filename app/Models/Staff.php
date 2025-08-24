<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class Staff extends Authenticatable
{
    use HasFactory;

    protected $guarded = [];

    protected $hidden = [
        'pin_code',
        'setup_code',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_login_at' => 'datetime',
    ];

    public function rfidTags()
    {
        return $this->hasMany(RfidTag::class);
    }

    public function activeRfidTags()
    {
        return $this->hasMany(RfidTag::class)->where('is_active', true);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function updateLastLogin()
    {
        $this->update(['last_login_at' => now()]);
    }

    /**
     * Generate a unique 6-character alphanumeric setup code
     */
    public function generateSetupCode(): string
    {
        do {
            $code = strtoupper(substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 6));
        } while (self::where('setup_code', $code)->exists());

        $this->update(['setup_code' => $code]);

        return $code;
    }

    /**
     * Check if staff member has a setup code
     */
    public function hasSetupCode(): bool
    {
        return ! empty($this->setup_code);
    }

    /**
     * Clear setup code after successful setup
     */
    public function clearSetupCode(): void
    {
        $this->update(['setup_code' => null]);
    }

    /**
     * Validate PIN code strength and uniqueness
     */
    public static function validatePinStrength(string $pin, ?int $excludeStaffId = null): array
    {
        $errors = [];

        // Check length
        if (strlen($pin) !== 6) {
            $errors[] = 'PIN must be exactly 6 digits.';
        }

        // Check if all numeric
        if (! ctype_digit($pin)) {
            $errors[] = 'PIN must contain only numbers.';
        }

        // Check uniqueness (don't reveal that another user has this PIN for security)
        $query = self::where('pin_code', $pin);
        if ($excludeStaffId) {
            $query->where('id', '!=', $excludeStaffId);
        }

        if ($query->exists()) {
            $errors[] = 'This PIN is not secure enough. Please choose a different PIN.';
        }

        // Common weak PINs
        $weakPins = [
            '111111', '222222', '333333', '444444', '555555', '666666', '777777', '888888', '999999', '000000',
            '123456', '654321', '123123', '456456', '789789',
            '000001', '111112', '123321', '112233', '121212',
            '101010', '202020', '303030', '404040', '505050',
        ];

        if (in_array($pin, $weakPins)) {
            $errors[] = 'This PIN is too common. Please choose a more secure PIN.';
        }

        // Check for sequential numbers
        $isSequential = true;
        for ($i = 1; $i < strlen($pin); $i++) {
            if ((int) $pin[$i] !== (int) $pin[$i - 1] + 1) {
                $isSequential = false;
                break;
            }
        }
        if ($isSequential) {
            $errors[] = 'PIN cannot be sequential numbers (e.g., 123456).';
        }

        return $errors;
    }
}
