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
        Schema::table('badges', function (Blueprint $table) {
            if (SchemaGuard::missingColumn('badges', 'custom_id')) {
                $table->after('fursuit_id', function (Blueprint $table) {
                    $table->integer('custom_id')->default(1);
                });
            }
        });
    }
};
