<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\FCEA\UserCatchLog */
class user_catch_logResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'id' => $this->id,
            'catch_code' => $this->catch_code,
            'is_successful' => $this->is_successful,
            'already_caught' => $this->already_caught,

            'user_id' => $this->user_id,
            'user_id' => $this->user_id,
        ];
    }
}
