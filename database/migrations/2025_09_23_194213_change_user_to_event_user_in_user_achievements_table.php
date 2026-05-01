<?php

use App\Models\Event;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Step 1: Add new event_user_id column to user_achievements
        Schema::table('user_achievements', function (Blueprint $table) {
            $table->unsignedBigInteger('event_user_id')->nullable()->after('user_id');
            $table->foreign('event_user_id')->references('id')->on('event_users')->onUpdate('cascade')->onDelete('restrict');
        });

        $update = Event::where('id', 2)->count() > 0; // EF 29 - ensure event exists before running update query

        if ($update) {
            // Step 2: Migrate data from user_id to event_user_id
            DB::statement('
            UPDATE user_achievements ua
            JOIN event_users eu ON ua.user_id = eu.user_id AND eu.event_id = ?
            SET ua.event_user_id = eu.id
        ', [2]);
        } // EF 29

        // Step 3: Make event_user_id non-nullable and remove old user_id column
        Schema::table('user_achievements', function (Blueprint $table) {
            $table->unsignedBigInteger('event_user_id')->nullable(false)->index()->change();
            $table->unique(['achievement', 'event_user_id']);

            $table->dropIndex('user_achievements_user_id_earned_at_index');

            $table->dropUnique('user_achievements_user_id_achievement_unique');
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });

        // Step 4: Add new event_user_id column to user_catches
        Schema::table('user_catches', function (Blueprint $table) {
            $table->unsignedBigInteger('event_user_id')->nullable()->after('id');
            $table->foreign('event_user_id')->references('id')->on('event_users')->onUpdate('cascade')->onDelete('restrict');
        });

        // Step 5: Migrate data in user_catches
        if ($update) {
            DB::statement('
            UPDATE user_catches uc
            JOIN event_users eu
                ON uc.user_id = eu.user_id AND uc.event_id = eu.event_id
            SET uc.event_user_id = eu.id;
        ');
        } // EF 29

        // Step 6: Remove old columns from user_catches
        Schema::table('user_catches', function (Blueprint $table) {
            $table->unsignedBigInteger('event_user_id')->nullable(false)->index()->change();
            $table->unique(['fursuit_id', 'event_user_id']);

            $table->dropIndex('idx_user_catches_user_created')

            $table->dropUnique('user_catches_user_id_fursuit_id_unique');
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
            $table->dropForeign(['event_id']);
            $table->dropColumn('event_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_achievements', function (Blueprint $table) {});
    }
};
