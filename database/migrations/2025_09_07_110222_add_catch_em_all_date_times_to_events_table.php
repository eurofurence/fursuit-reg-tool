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
        Schema::table('events', function (Blueprint $table) {
            $table->dateTime('catch_em_all_start')->nullable()->after('catch_em_all_enabled');
            $table->dateTime('catch_em_all_end')->nullable()->after('catch_em_all_start');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('catch_em_all_start');
            $table->dropColumn('catch_em_all_end');
        });
    }
};
