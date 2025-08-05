<?php

namespace App\Domain\CatchEmAll\Services;

use App\Domain\CatchEmAll\Enums\Achievement;
use App\Domain\CatchEmAll\Enums\SpeciesRarity;
use App\Domain\CatchEmAll\Models\UserAchievement;
use App\Domain\CatchEmAll\Models\UserCatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AchievementService
{
    public function processAchievements(User $user, UserCatch $newCatch): void
    {
        $this->checkFirstCatch($user);
        $this->checkSpeciesCollector($user);
        $this->checkRarityAchievements($user, $newCatch);
        $this->checkSpeedDemon($user);
        $this->checkSocialButterfly($user);
        $this->checkDedication($user);
        $this->checkCompletionist($user);
    }

    public function checkCheatingBehavior(User $user): void
    {
        // Check for suspicious patterns
        $recentAttempts = DB::table('user_catch_logs')
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->subHour())
            ->count();

        $failureRate = DB::table('user_catch_logs')
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->subDay())
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN is_successful = 0 THEN 1 ELSE 0 END) as failures')
            ->first();

        $suspiciousActivity = false;

        // Too many attempts in short time
        if ($recentAttempts > 100) {
            $suspiciousActivity = true;
        }

        // Very low failure rate (possible code sharing)
        if ($failureRate->total > 50 && ($failureRate->failures / $failureRate->total) < 0.1) {
            $suspiciousActivity = true;
        }

        if ($suspiciousActivity) {
            $this->grantAchievement($user, Achievement::CHEATER);
        }
    }

    private function checkFirstCatch(User $user): void
    {
        $catchCount = UserCatch::where('user_id', $user->id)->count();
        
        if ($catchCount === 1) {
            $this->grantAchievement($user, Achievement::FIRST_CATCH);
        }
    }

    private function checkSpeciesCollector(User $user): void
    {
        $speciesCount = UserCatch::where('user_id', $user->id)
            ->join('fursuits', 'user_catches.fursuit_id', '=', 'fursuits.id')
            ->distinct('fursuits.species_id')
            ->count();

        $this->updateProgressAchievement($user, Achievement::SPECIES_COLLECTOR, $speciesCount, 10);
    }

    private function checkRarityAchievements(User $user, UserCatch $newCatch): void
    {
        $rarity = $newCatch->getSpeciesRarity();

        // Check for rare catches
        if ($rarity === SpeciesRarity::RARE) {
            $rareCount = $this->getRarityCatchCount($user, SpeciesRarity::RARE);
            $this->updateProgressAchievement($user, Achievement::RARE_HUNTER, $rareCount, 5);
        }

        // Check for epic catches
        if ($rarity === SpeciesRarity::EPIC) {
            $epicCount = $this->getRarityCatchCount($user, SpeciesRarity::EPIC);
            $this->updateProgressAchievement($user, Achievement::EPIC_SEEKER, $epicCount, 3);
        }

        // Check for legendary catches
        if ($rarity === SpeciesRarity::LEGENDARY) {
            $this->grantAchievement($user, Achievement::LEGENDARY_MASTER);
        }
    }

    private function checkSpeedDemon(User $user): void
    {
        $recentCatches = UserCatch::where('user_id', $user->id)
            ->where('created_at', '>=', now()->subHour())
            ->count();

        $this->updateProgressAchievement($user, Achievement::SPEED_DEMON, $recentCatches, 10);
    }

    private function checkSocialButterfly(User $user): void
    {
        $uniqueFursuiters = UserCatch::where('user_id', $user->id)
            ->distinct('fursuit_id')
            ->count();

        $this->updateProgressAchievement($user, Achievement::SOCIAL_BUTTERFLY, $uniqueFursuiters, 50);
    }

    private function checkDedication(User $user): void
    {
        $daysWithCatches = UserCatch::where('user_id', $user->id)
            ->selectRaw('DATE(created_at) as catch_date')
            ->distinct()
            ->count();

        $this->updateProgressAchievement($user, Achievement::DEDICATION, $daysWithCatches, 3);
    }

    private function checkCompletionist(User $user): void
    {
        // This would need to be event-specific
        $totalCatches = UserCatch::where('user_id', $user->id)->count();
        $totalAvailable = DB::table('fursuits')
            ->where('catch_em_all', true)
            ->count();

        if ($totalAvailable > 0 && $totalCatches >= $totalAvailable) {
            $this->grantAchievement($user, Achievement::COMPLETIONIST);
        }
    }

    private function getRarityCatchCount(User $user, SpeciesRarity $rarity): int
    {
        $catches = UserCatch::where('user_id', $user->id)
            ->with(['fursuit.species'])
            ->get();

        return $catches->filter(function ($catch) use ($rarity) {
            return $catch->getSpeciesRarity() === $rarity;
        })->count();
    }

    private function grantAchievement(User $user, Achievement $achievement): bool
    {
        $existing = UserAchievement::where('user_id', $user->id)
            ->where('achievement', $achievement)
            ->first();

        if ($existing && $existing->earned_at) {
            return false; // Already earned
        }

        if (!$existing) {
            $existing = new UserAchievement([
                'user_id' => $user->id,
                'achievement' => $achievement,
                'progress' => 0,
                'max_progress' => 1,
            ]);
        }

        $existing->progress = $existing->max_progress;
        $existing->earned_at = now();
        $existing->save();

        return true;
    }

    private function updateProgressAchievement(User $user, Achievement $achievement, int $progress, int $maxProgress): bool
    {
        $existing = UserAchievement::firstOrCreate([
            'user_id' => $user->id,
            'achievement' => $achievement,
        ], [
            'progress' => 0,
            'max_progress' => $maxProgress,
        ]);

        $existing->progress = min($progress, $maxProgress);
        
        if ($existing->progress >= $maxProgress && !$existing->earned_at) {
            $existing->earned_at = now();
            $existing->save();
            return true; // Achievement earned
        }

        $existing->save();
        return false;
    }
}