<?php

use App\Support\Migrations\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('species', function (Blueprint $table) {
            if (! SchemaGuard::hasIndex('species', 'name')) {
                $table->unique('name');
            }
        });
    }
};
