<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('special_codes', function (Blueprint $table) {
            $table->index('code');
            $table->index('event_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('special_codes', function (Blueprint $table) {
            $table->dropIndex(['code']);
            $table->dropIndex(['event_id']);
        });
    }
};
