<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill for bugfix-04: badge/fursuit soft-deletes were not cascaded, leaving orphans
 * (20+ fursuits with no remaining badges, and possibly badges under already-deleted fursuits).
 *
 * Repairs the existing inconsistency by soft-deleting the orphaned rows. Idempotent: both steps
 * are WHERE-guarded UPDATEs that only touch still-inconsistent rows, so re-running converges
 * (safe under the ArgoCD PreSync migrate job — see CLAUDE.md "Migrations must be idempotent").
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // 1) Soft-delete active fursuits that have no remaining (non-trashed) badge.
        DB::table('fursuits')
            ->whereNull('deleted_at')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('badges')
                    ->whereColumn('badges.fursuit_id', 'fursuits.id')
                    ->whereNull('badges.deleted_at');
            })
            ->update(['deleted_at' => $now, 'updated_at' => $now]);

        // 2) Symmetrically, soft-delete active badges whose parent fursuit is already trashed.
        DB::table('badges')
            ->whereNull('deleted_at')
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('fursuits')
                    ->whereColumn('fursuits.id', 'badges.fursuit_id')
                    ->whereNotNull('fursuits.deleted_at');
            })
            ->update(['deleted_at' => $now, 'updated_at' => $now]);
    }

    public function down(): void
    {
        // Data repair only; not reversible.
    }
};
