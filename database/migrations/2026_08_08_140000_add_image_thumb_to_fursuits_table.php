<?php

use App\Support\Migrations\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (SchemaGuard::missingColumn('fursuits', 'image_thumb')) {
            Schema::table('fursuits', function (Blueprint $table) {
                $table->string('image_thumb')->nullable()->after('image_webp');
            });
        }
    }

    public function down(): void
    {
        if (SchemaGuard::hasColumn('fursuits', 'image_thumb')) {
            Schema::table('fursuits', function (Blueprint $table) {
                $table->dropColumn('image_thumb');
            });
        }
    }
};
