<?php

namespace App\Http\Resources;

use App\Models\Fursuit\Fursuit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

/** @see Fursuit */
class FursuitCollection extends ResourceCollection
{
    public function toArray(Request $request): array
    {
        return [
            'data' => FursuitResource::collection($this->collection),
        ];
    }
}
