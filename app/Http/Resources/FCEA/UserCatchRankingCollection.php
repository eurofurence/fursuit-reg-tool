<?php

namespace App\Http\Resources;

use App\Models\FCEA\UserCatchRanking;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

/** @see UserCatchRanking */
class UserCatchRankingCollection extends ResourceCollection
{
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection,
        ];
    }
}
