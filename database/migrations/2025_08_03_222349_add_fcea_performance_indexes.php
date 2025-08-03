<?php

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
        Schema::table('fursuits', function (Blueprint $table) {
            // Critical for catch code lookups
            $table->index('catch_code', 'idx_fursuits_catch_code');
        });

        Schema::table('user_catches', function (Blueprint $table) {
            // For duplicate checking
            $table->index(['user_id', 'fursuit_id'], 'idx_user_catches_user_fursuit');
            // For ranking queries
            $table->index(['user_id', 'created_at'], 'idx_user_catches_user_created');
            $table->index(['fursuit_id', 'created_at'], 'idx_user_catches_fursuit_created');
        });

        Schema::table('user_catch_rankings', function (Blueprint $table) {
            // For ranking queries
            $table->index('user_id', 'idx_user_catch_rankings_user');
            $table->index('fursuit_id', 'idx_user_catch_rankings_fursuit');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fursuits', function (Blueprint $table) {
            $table->dropIndex('idx_fursuits_catch_code');
        });

        Schema::table('user_catches', function (Blueprint $table) {
            $table->dropIndex('idx_user_catches_user_fursuit');
            $table->dropIndex('idx_user_catches_user_created');
            $table->dropIndex('idx_user_catches_fursuit_created');
        });

        Schema::table('user_catch_rankings', function (Blueprint $table) {
            $table->dropIndex('idx_user_catch_rankings_user');
            $table->dropIndex('idx_user_catch_rankings_fursuit');
        });
    }
};
