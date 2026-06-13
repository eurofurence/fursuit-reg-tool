<?php

use App\Support\Migrations\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('printers', function (Blueprint $table) {
            $table->after('paper_sizes', function (Blueprint $table) {
                if (SchemaGuard::missingColumn('printers', 'is_active')) {
                    $table->boolean('is_active')->default(false);
                }
                if (SchemaGuard::missingColumn('printers', 'is_double')) {
                    $table->boolean('is_double')->default(false);
                }
            });
        });
    }

    public function down(): void
    {
        Schema::table('printers', function (Blueprint $table) {
            //
        });
    }
};
