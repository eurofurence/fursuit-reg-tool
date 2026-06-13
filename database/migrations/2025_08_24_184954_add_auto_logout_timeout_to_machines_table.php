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
        Schema::table('machines', function (Blueprint $table) {
            if (SchemaGuard::missingColumn('machines', 'auto_logout_timeout')) {
                $table->integer('auto_logout_timeout')->nullable()->default(300)->after('qz_last_seen_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            if (SchemaGuard::hasColumn('machines', 'auto_logout_timeout')) {
                $table->dropColumn('auto_logout_timeout');
            }
        });
    }
};
