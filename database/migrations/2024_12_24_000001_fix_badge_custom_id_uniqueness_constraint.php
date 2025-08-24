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
        Schema::table('badges', function (Blueprint $table) {
            // Drop the global unique constraint on custom_id
            $table->dropUnique(['custom_id']);

            // Add an index for performance, but not unique globally
            $table->index('custom_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('badges', function (Blueprint $table) {
            // Drop the index
            $table->dropIndex(['custom_id']);

            // Re-add the global unique constraint
            $table->unique('custom_id');
        });
    }
};
