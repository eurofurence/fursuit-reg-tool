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
        // Change status column from ENUM to VARCHAR - NEVER use database ENUMs!
        Schema::table('printers', function (Blueprint $table) {
            $table->string('status')->default('idle')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Keep as string - NEVER revert back to ENUM
        Schema::table('printers', function (Blueprint $table) {
            $table->string('status')->default('idle')->change();
        });
    }
};
