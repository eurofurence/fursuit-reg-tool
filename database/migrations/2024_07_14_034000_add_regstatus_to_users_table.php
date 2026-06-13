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
            if (SchemaGuard::missingColumn('users', 'valid_registration')) {
                $table->boolean('valid_registration')->after('remote_id')->nullable();
            }
        });
    }
};
