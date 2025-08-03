<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fursuits', function (Blueprint $table) {
            $table->index('status');
        });
        Schema::table('badges', function (Blueprint $table) {
            $table->index('status');
        });
    }
};
