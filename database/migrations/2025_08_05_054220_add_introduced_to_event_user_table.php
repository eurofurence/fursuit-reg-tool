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
        Schema::table('event_users', function (Blueprint $table) {
            if (SchemaGuard::missingColumn('event_users', 'catch_em_all_introduced')) {
                $table->boolean('catch_em_all_introduced')->default(false)->after('updated_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('event_users', function (Blueprint $table) {
            if (SchemaGuard::hasColumn('event_users', 'catch_em_all_introduced')) {
                $table->dropColumn('catch_em_all_introduced');
            }
        });
    }
};
