<?php

namespace App\Models\FCEA;

use App\Models\Fursuit\Fursuit;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

// Model of the Ranking entrys for fursuits and users. Contains user_id and fursuit_id but only one of them is used at a time.
// Depends if it is a user ranking entry or fursuit ranking entry
class UserCatchRanking extends Model
{
    public $timestamps = false;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fursuit(): BelongsTo
    {
        return $this->belongsTo(Fursuit::class);
    }

    public function hasUser(): bool
    {
        return $this->user_id <> null;
    }

    public function hasFursuit(): bool
    {
        return $this->fursuit_id <> null;
    }

    static public function getInfoOfUser(int $userId): UserCatchRanking|null
    {
        return UserCatchRanking::where('user_id', '=', $userId)->with("user")->with("fursuit")->first();
    }
    static public function getInfoOfFursuit(int $fursuitID): UserCatchRanking|null
    {
        return UserCatchRanking::where('fursuit_id', '=', $fursuitID)->with("fursuit")->first();
    }
    static public function getInfoOfFursuits(array $fursuitIDs): Collection
    {
        return UserCatchRanking::whereBetween('fursuit_id', $fursuitIDs)->with("fursuit")->get();
    }

    static public function deleteUserRanking(): void
    {
        UserCatchRanking::where('user_id', '<>', null)->delete();
    }
    static public function deleteFursuitRanking(): void
    {
        UserCatchRanking::where('fursuit_id', '<>', null)->delete();
    }

    static public function queryUserRanking(): Builder
    {
        return UserCatchRanking::where('user_id', '<>', null)->with("user");
    }
    static public function queryFursuitRanking(): Builder
    {
        return UserCatchRanking::where('fursuit_id', '<>', null)->with("fursuit");
    }

    protected function casts(): array
    {
        return [
            'score_reached_at' => 'timestamp',
        ];
    }
}
