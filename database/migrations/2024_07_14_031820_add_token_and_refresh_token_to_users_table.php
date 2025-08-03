<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->after('remote_id', function (Blueprint $table) {
                $table->text('token')->nullable();
                $table->dateTime('token_expires_at')->nullable();
                $table->text('refresh_token')->nullable();
                $table->dateTime('refresh_token_expires_at')->nullable();
            });
        });
    }
};
