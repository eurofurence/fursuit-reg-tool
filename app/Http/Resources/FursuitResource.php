<?php

namespace App\Http\Resources;

use App\Models\Species;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Fursuit\Fursuit
 * Builds a array with relevant data from fursuits
 * Filters out unnecessary details
 * withCount("badges"), with("species") and with("user") are recommended to save database load
 * */
class FursuitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reg_id' => $this->user->eventUser($this->event_id)?->attendee_id ?? null,
            'status' => $this->status,
            'name' => $this->name,
            'published' => $this->published,
            'catch_em_all' => $this->catch_em_all,
            'image_url' => $this->image_url,
            'badges_count' => $this->badges_count,
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ],
            'species' => $this->species->only(['id', 'name', 'type']),
        ];
    }
}
