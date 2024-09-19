<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('printers', function (Blueprint $table) {
            $table->after('paper_sizes', function (Blueprint $table) {
                $table->boolean('is_active')->default(false);
                $table->boolean('is_double')->default(false);
            });
        });
    }

    public function down(): void
    {
        Schema::table('printers', function (Blueprint $table) {
            //
        });
    }
};
