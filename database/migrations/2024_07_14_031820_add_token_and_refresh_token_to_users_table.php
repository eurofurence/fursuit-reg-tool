<?php

use App\Support\Migrations\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->after('remote_id', function (Blueprint $table) {
                if (SchemaGuard::missingColumn('users', 'token')) {
                    $table->text('token')->nullable();
                }
                if (SchemaGuard::missingColumn('users', 'token_expires_at')) {
                    $table->dateTime('token_expires_at')->nullable();
                }
                if (SchemaGuard::missingColumn('users', 'refresh_token')) {
                    $table->text('refresh_token')->nullable();
                }
                if (SchemaGuard::missingColumn('users', 'refresh_token_expires_at')) {
                    $table->dateTime('refresh_token_expires_at')->nullable();
                }
            });
        });
    }
};
