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
        Schema::table('special_codes', function (Blueprint $table) {
            if (! SchemaGuard::hasIndex('special_codes', 'special_codes_code_index')) {
                $table->index('code');
            }
            if (! SchemaGuard::hasIndex('special_codes', 'special_codes_event_id_index')) {
                $table->index('event_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('special_codes', function (Blueprint $table) {
            if (SchemaGuard::hasIndex('special_codes', 'special_codes_code_index')) {
                $table->dropIndex(['code']);
            }
            if (SchemaGuard::hasIndex('special_codes', 'special_codes_event_id_index')) {
                $table->dropIndex(['event_id']);
            }
        });
    }
};
