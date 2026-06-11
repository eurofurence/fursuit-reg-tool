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
            if (SchemaGuard::hasColumn('users', 'attendee_id')) {
                $table->dropColumn('attendee_id');
            }
            if (SchemaGuard::hasColumn('users', 'valid_registration')) {
                $table->dropColumn('valid_registration');
            }
            if (SchemaGuard::hasColumn('users', 'has_free_badge')) {
                $table->dropColumn('has_free_badge');
            }
            if (SchemaGuard::hasColumn('users', 'free_badge_copies')) {
                $table->dropColumn('free_badge_copies');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (SchemaGuard::missingColumn('users', 'attendee_id')) {
                $table->string('attendee_id')->nullable();
            }
            if (SchemaGuard::missingColumn('users', 'valid_registration')) {
                $table->boolean('valid_registration')->nullable();
            }
            if (SchemaGuard::missingColumn('users', 'has_free_badge')) {
                $table->boolean('has_free_badge')->default(false);
            }
            if (SchemaGuard::missingColumn('users', 'free_badge_copies')) {
                $table->integer('free_badge_copies')->default(0);
            }
        });
    }
};
