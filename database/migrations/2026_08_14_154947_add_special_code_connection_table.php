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
        if (SchemaGuard::missingTable('special_code_connection')) {
            Schema::create('special_code_connection', function (Blueprint $table) {
                $table->foreignId('special_code_id');
                $table->foreignId('event_users_id');
                $table->foreign('special_code_id')->references('id')->on('special_codes')->onDelete('cascade');
                $table->foreign('event_users_id')->references('id')->on('event_users')->onDelete('cascade');
                $table->primary(['special_code_id', 'event_users_id']);
                $table->index('event_users_id');
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('special_code_connection');
    }
};
