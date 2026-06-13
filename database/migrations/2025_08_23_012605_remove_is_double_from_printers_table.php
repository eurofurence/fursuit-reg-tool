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
        Schema::table('printers', function (Blueprint $table) {
            if (SchemaGuard::hasColumn('printers', 'is_double')) {
                $table->dropColumn('is_double');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('printers', function (Blueprint $table) {
            if (SchemaGuard::missingColumn('printers', 'is_double')) {
                $table->boolean('is_double')->default(false);
            }
        });
    }
};
