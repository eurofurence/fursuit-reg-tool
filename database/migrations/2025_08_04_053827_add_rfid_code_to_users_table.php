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
        Schema::table('users', function (Blueprint $table) {
            $table->after('pin_code', function (Blueprint $table) {
                if (SchemaGuard::missingColumn('users', 'rfid_code')) {
                    $table->string('rfid_code')->nullable()->unique();
                }
            });
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (SchemaGuard::hasColumn('users', 'rfid_code')) {
                $table->dropColumn('rfid_code');
            }
        });
    }
};
