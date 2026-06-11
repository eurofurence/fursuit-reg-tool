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
            if (SchemaGuard::hasColumn('events', 'allow_orders_at')) {
                $table->dropColumn('allow_orders_at');
            }
            if (SchemaGuard::missingColumn('events', 'order_starts_at')) {
                $table->dateTime('order_starts_at')->nullable()->after('order_ends_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (SchemaGuard::hasColumn('events', 'order_starts_at')) {
                $table->dropColumn('order_starts_at');
            }
            if (SchemaGuard::missingColumn('events', 'allow_orders_at')) {
                $table->dateTime('allow_orders_at')->nullable()->after('order_ends_at');
            }
        });
    }
};
