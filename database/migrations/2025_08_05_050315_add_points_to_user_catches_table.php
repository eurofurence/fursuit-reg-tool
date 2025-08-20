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
        Schema::table('user_catches', function (Blueprint $table) {
            $table->integer('points_earned')->default(1)->after('fursuit_id');
            $table->index(['user_id', 'points_earned']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_catches', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'points_earned']);
            $table->dropColumn('points_earned');
        });
    }
};
