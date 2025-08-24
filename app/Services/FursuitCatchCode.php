<?php

namespace App\Services;

// Simple class for the Catch Em All code fursuits receive.
// generate() outputs a X digit Number/Character code which is not used yet
class FursuitCatchCode
{
    public function __construct(protected string $model, protected string $column) {}

    public function generate(): string
    {
        do {
            $identifier = strtoupper(substr(str_shuffle(str_repeat('ABCEFGHJKLMNPRSTUVWXYZ', 5)), 0, config('fcea.fursuit_catch_code_length', 5)));
        } while ($this->model::where($this->column, $identifier)->exists());

        return $identifier;
    }
}
