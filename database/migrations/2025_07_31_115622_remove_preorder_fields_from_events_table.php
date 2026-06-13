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
        Schema::table('events', function (Blueprint $table) {
            if (SchemaGuard::hasColumn('events', 'preorder_starts_at')) {
                $table->dropColumn('preorder_starts_at');
            }
            if (SchemaGuard::hasColumn('events', 'preorder_ends_at')) {
                $table->dropColumn('preorder_ends_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (SchemaGuard::missingColumn('events', 'preorder_starts_at')) {
                $table->dateTime('preorder_starts_at')->nullable()->after('ends_at');
            }
            if (SchemaGuard::missingColumn('events', 'preorder_ends_at')) {
                $table->dateTime('preorder_ends_at')->after('preorder_starts_at');
            }
        });
    }
};
