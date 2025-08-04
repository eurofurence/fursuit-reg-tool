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
            $table->dropColumn(['attendee_id', 'valid_registration', 'has_free_badge', 'free_badge_copies']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('attendee_id')->nullable();
            $table->boolean('valid_registration')->nullable();
            $table->boolean('has_free_badge')->default(false);
            $table->integer('free_badge_copies')->default(0);
        });
    }
};
