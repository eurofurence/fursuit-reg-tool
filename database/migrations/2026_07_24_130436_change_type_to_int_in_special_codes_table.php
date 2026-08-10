<?php

use App\Support\Migrations\SchemaGuard;
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
        if (SchemaGuard::hasColumn('special_codes', 'type')) {
            Schema::table('special_codes', function (Blueprint $table) {
                // Clear eintiere table to avoid issues with enum values
                \DB::table('special_codes')->truncate();

                $table->integer('type')->nullable(false)->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('specials_codes', function (Blueprint $table) {
            //
        });
    }
};
