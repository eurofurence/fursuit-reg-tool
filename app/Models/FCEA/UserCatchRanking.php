<?php

namespace App\Models\FCEA;

use App\Models\Fursuit\Fursuit;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        return $this->user_id != null;
    }

    public function hasFursuit(): bool
    {
        return $this->fursuit_id != null;
    }

    public static function getInfoOfUser(int $userId): ?UserCatchRanking
    {
        return UserCatchRanking::where('user_id', '=', $userId)
            ->with('user')
            ->first();
    }

    public static function getInfoOfFursuit(int $fursuitID): ?UserCatchRanking
    {
        return UserCatchRanking::where('fursuit_id', '=', $fursuitID)
            ->with(['fursuit.species', 'fursuit.user'])
            ->first();
    }

    public static function getInfoOfFursuits(array $fursuitIDs): Collection
    {
        return UserCatchRanking::whereIn('fursuit_id', $fursuitIDs)
            ->with(['fursuit.species', 'fursuit.user'])
            ->get();
    }

    public static function deleteUserRanking(): void
    {
        UserCatchRanking::whereNotNull('user_id')->delete();
    }

    public static function deleteFursuitRanking(): void
    {
        UserCatchRanking::whereNotNull('fursuit_id')->delete();
    }

    public static function queryUserRanking(): Builder
    {
        return UserCatchRanking::whereNotNull('user_id')->with('user');
    }

    public static function queryFursuitRanking(): Builder
    {
        return UserCatchRanking::whereNotNull('fursuit_id')
            ->with(['fursuit.species', 'fursuit.user']);
    }

    protected function casts(): array
    {
        return [
            'score_reached_at' => 'timestamp',
        ];
    }
}
