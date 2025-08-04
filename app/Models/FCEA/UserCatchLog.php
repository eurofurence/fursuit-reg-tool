<?php

namespace App\Models\FCEA;

use App\Models\Fursuit\Fursuit;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

// Model to manage Logging for the Catch feature. Attempts/Successful/Duplicates. Allows to detect Cheating/Bruteforcing.
class UserCatchLog extends Model
{
    // Simple caching so save database lookups
    protected ?Fursuit $fursuit = null;

    protected $casts = [
        'is_successful' => 'boolean',
        'already_caught' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Look if a Fursuit Exist with the given catch code - might be null
    public function tryGetFursuit(): ?Fursuit
    {
        // If Fursuit not found yet or catch_code not correct -> lookup Fursuit
        if (! $this->fursuit || $this->fursuit->catch_code != $this->catch_code) {
            // Use cache for fursuit lookups to reduce database queries
            $this->fursuit = Cache::remember(
                "fursuit_code_{$this->catch_code}",
                3600, // Cache for 1 hour
                function () {
                    return Fursuit::where('catch_code', $this->catch_code)
                        ->where('catch_em_all', true)
                        ->first();
                }
            );
        }

        return $this->fursuit;
    }

    // True means tryGetFursuit() will give a result. False means tryGetFursuit() will give null.
    public function fursuitExist(): bool
    {
        return $this->tryGetFursuit() !== null;
    }

    /**
     * Clear fursuit cache when a fursuit's catch code changes
     */
    public static function clearFursuitCache(string $catchCode): void
    {
        Cache::forget("fursuit_code_{$catchCode}");
    }
}
