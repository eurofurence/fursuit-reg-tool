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
            return;
        }

        if (SchemaGuard::hasForeignKeyOn('special_code_connection', 'special_code_id')
            && ! SchemaGuard::hasForeignKeyTo('special_code_connection', 'special_code_id', 'special_codes')) {
            Schema::table('special_code_connection', function (Blueprint $table) {
                $table->dropForeign(['special_code_id']);
            });
        }

        if (SchemaGuard::hasForeignKeyOn('special_code_connection', 'event_users_id')
            && ! SchemaGuard::hasForeignKeyTo('special_code_connection', 'event_users_id', 'event_users')) {
            Schema::table('special_code_connection', function (Blueprint $table) {
                $table->dropForeign(['event_users_id']);
            });
        }

        Schema::table('special_code_connection', function (Blueprint $table) {
            if (! SchemaGuard::hasForeignKeyOn('special_code_connection', 'special_code_id')) {
                $table->foreign('special_code_id')->references('id')->on('special_codes')->cascadeOnDelete();
            }

            if (! SchemaGuard::hasForeignKeyOn('special_code_connection', 'event_users_id')) {
                $table->foreign('event_users_id')->references('id')->on('event_users')->cascadeOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (SchemaGuard::missingTable('special_code_connection')) {
            return;
        }

        Schema::table('special_code_connection', function (Blueprint $table) {
            if (SchemaGuard::hasForeignKeyTo('special_code_connection', 'special_code_id', 'special_codes')) {
                $table->dropForeign(['special_code_id']);
            }

            if (SchemaGuard::hasForeignKeyTo('special_code_connection', 'event_users_id', 'event_users')) {
                $table->dropForeign(['event_users_id']);
            }
        });
    }
};
