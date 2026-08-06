<?php

use App\Support\Migrations\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (SchemaGuard::missingColumn('users', 'avatar_path')) {
                $table->string('avatar_path')->nullable()->after('avatar');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (SchemaGuard::hasColumn('users', 'avatar_path')) {
                $table->dropColumn('avatar_path');
            }
        });
    }
};
