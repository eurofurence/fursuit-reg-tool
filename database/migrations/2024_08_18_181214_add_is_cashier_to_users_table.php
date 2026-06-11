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
            $table->after('is_reviewer', function (Blueprint $table) {
                if (SchemaGuard::missingColumn('users', 'is_cashier')) {
                    $table->boolean('is_cashier')->default(false);
                }
                if (SchemaGuard::missingColumn('users', 'pin_code')) {
                    $table->string('pin_code')->nullable();
                }
            });
        });
    }
};
