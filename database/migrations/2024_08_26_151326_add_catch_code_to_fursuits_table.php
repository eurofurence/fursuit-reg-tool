<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('fursuits', function (Blueprint $table) {
            $table->after('catch_em_all', function (Blueprint $table) {
                $table->string('catch_code', length: 255)->nullable()->unique();
            });
        });
    }
};
