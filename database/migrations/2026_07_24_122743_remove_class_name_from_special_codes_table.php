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
        if (SchemaGuard::hasColumn('special_codes', 'class_name')) {
            Schema::table('special_codes', function (Blueprint $table) {
                $table->dropColumn('class_name');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('special_codes', function (Blueprint $table) {
            //
        });
    }
};
