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
            if (SchemaGuard::missingColumn('users', 'has_free_badge')) {
                $table->boolean('has_free_badge')->default(false)->after('remember_token');
            }
            if (SchemaGuard::missingColumn('users', 'free_badge_copies')) {
                $table->integer('free_badge_copies')->default(0)->after('has_free_badge');
            }
        });
    }
};
