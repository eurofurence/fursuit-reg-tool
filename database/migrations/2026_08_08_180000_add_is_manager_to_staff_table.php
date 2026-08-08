<?php

use App\Support\Migrations\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            if (SchemaGuard::missingColumn('staff', 'is_manager')) {
                $table->boolean('is_manager')->default(false)->after('is_active');
            }
        });
    }

    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            if (SchemaGuard::hasColumn('staff', 'is_manager')) {
                $table->dropColumn('is_manager');
            }
        });
    }
};
