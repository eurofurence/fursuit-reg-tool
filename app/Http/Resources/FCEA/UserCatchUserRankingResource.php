<?php

namespace App\Http\Resources;

use App\Models\Species;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\FCEA\UserCatchUserRanking
 * Builds a array with relevant data from user_catch_user_rankings
 * Filters out unnecessary details
 * withCount("user"), with("fursuit") and with("species") are recommended to save database load
 * */
class UserCatchUserRankingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            "reg_id" => $this->user->attendee_id,
            'status' => $this->status,
            'name' => $this->name,
            'published' => $this->published,
            'catch_em_all' => $this->catch_em_all,
            'image_url' => $this->image_url,
            'badges_count' => $this->badges_count,

            "species" => $this->species->only(["id", "name", "type"]),
        ];
    }
}
