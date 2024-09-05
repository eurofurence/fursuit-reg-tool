<?php

namespace App\Http\Resources\FCEA;

use App\Http\Resources\FursuitResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\FCEA\UserCatchRanking */
class UserCatchRankingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rank' => $this->rank,
            'score' => $this->score,
            'score_till_next' => $this->score_till_next,
            'others_behind' => $this->others_behind,
            'score_reached_at' => $this->score_reached_at,

            'user_id' => $this->user_id,
            'fursuit_id' => $this->fursuit_id,

            'fursuit' => new FursuitResource($this->whenLoaded('fursuit')),
        ];
    }
}
