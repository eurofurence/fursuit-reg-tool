<?php

use App\Support\Migrations\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fursuits', function (Blueprint $table) {
            $table->after('image', function (Blueprint $table) {
                if (SchemaGuard::missingColumn('fursuits', 'image_webp')) {
                    $table->string('image_webp')->nullable();
                }
            });
        });
    }

    public function down(): void
    {
        Schema::table('fursuits', function (Blueprint $table) {
            if (SchemaGuard::hasColumn('fursuits', 'image_webp')) {
                $table->dropColumn('image_webp');
            }
        });
    }
};
