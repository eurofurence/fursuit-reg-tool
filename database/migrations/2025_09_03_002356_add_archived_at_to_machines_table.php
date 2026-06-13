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
        if (SchemaGuard::missingColumn('machines', 'archived_at')) {
            Schema::table('machines', function (Blueprint $table) {
                $table->timestamp('archived_at')->nullable()->index();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (SchemaGuard::hasColumn('machines', 'archived_at')) {
            Schema::table('machines', function (Blueprint $table) {
                $table->dropColumn('archived_at');
            });
        }
    }
};
