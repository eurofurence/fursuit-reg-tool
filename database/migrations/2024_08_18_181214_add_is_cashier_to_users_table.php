<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->after('is_reviewer', function (Blueprint $table) {
                $table->boolean('is_cashier')->default(false);
                $table->string('pin_code')->nullable();
            });
        });
    }
};
