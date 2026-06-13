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
            if (SchemaGuard::missingColumn('events', 'allow_orders_at')) {
                $table->dateTime('allow_orders_at')->nullable()->after('order_ends_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (SchemaGuard::hasColumn('events', 'allow_orders_at')) {
                $table->dropColumn('allow_orders_at');
            }
        });
    }
};
