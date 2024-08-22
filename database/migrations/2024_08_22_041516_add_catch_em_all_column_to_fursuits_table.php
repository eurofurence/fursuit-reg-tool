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
        Schema::table('fursuits', function (Blueprint $table) {
            $table->string('catch_em_all_code')->nullable()->after('catch_em_all');
        });
    }
};
