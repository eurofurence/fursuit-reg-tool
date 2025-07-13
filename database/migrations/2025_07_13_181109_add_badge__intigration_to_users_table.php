<?php

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
            $table->tinyInteger('has_free_badge')->default(0)->after('remember_token');
            $table->integer('free_badge_copies')->default(0)->after('has_free_badge');
        });
    }
};
