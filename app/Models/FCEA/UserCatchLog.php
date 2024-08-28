<?php

namespace App\Models\FCEA;

use App\Models\Fursuit\Fursuit;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Model to manage Logging for the Catch feature. Attempts/Successful/Duplicates. Allows to detect Cheating/Bruteforcing.
class UserCatchLog extends Model
{
    // Simple caching so save database lookups
    protected Fursuit|null $fursuit = null;

    protected $casts = [
        'is_successful' => 'boolean',
        'already_caught' => 'boolean',
    ];

    public function user() : BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Look if a Fursuit Exist with the given catch code - might be null
    public function tryGetFursuit() : Fursuit|null
    {
        // If Fursuit not found yet ot catch_code not correct -> lookup Fursuit
        if (!$this->fursuit || $this->fursuit->catch_code != $this->catch_code)
            $this->fursuit = Fursuit::where("catch_code", $this->catch_code)->first();;
        return $this->fursuit;
    }

    // True means tryGetFursuit() will give an result. False means tryGetFursuit() will give null.
    public function FursuitExist() : bool
    {
        return $this->TryGetFursuit() != null;
    }
}
