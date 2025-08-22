<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

/** @see \App\Models\Fursuit\Fursuit */
class FursuitCollection extends ResourceCollection
{
    public function toArray(Request $request): array
    {
        return [
            'data' => FursuitResource::collection($this->collection),
        ];
    }
}
