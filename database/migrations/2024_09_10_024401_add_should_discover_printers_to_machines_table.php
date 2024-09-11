<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->boolean('should_discover_printers')->after('name')->default(true);
        });
    }
};
