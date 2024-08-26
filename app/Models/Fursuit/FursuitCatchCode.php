<?php

namespace App\Models\Fursuit;

use Illuminate\Support\Str;

// Simple class for the Catch Em All code fursuits receive.
// generate() outputs a X digit Number/Character code which is not used yet
class FursuitCatchCode
{
    public function __construct(protected string $model, protected string $column) {}

    public function generate(): string
    {
        do {
            $identifier = strtoupper(Str::password(
                $length = config("app.fursuit_catch_code_length"), // by default 5
                $letters = true,
                $numbers = true,
                $symbols = false,
                $spaces = false));
        } while ($this->model::where($this->column, $identifier)->exists());

        return $identifier;
    }
}
