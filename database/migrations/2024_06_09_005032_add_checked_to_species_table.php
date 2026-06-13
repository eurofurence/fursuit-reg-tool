<?php

use App\Support\Migrations\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('species', function (Blueprint $table) {
            if (SchemaGuard::missingColumn('species', 'checked')) {
                $table->boolean('checked')->default(false)->after('type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('species', function (Blueprint $table) {
            //
        });
    }
};
