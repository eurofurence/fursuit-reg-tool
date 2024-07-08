<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('badges', function (Blueprint $table) {
            $table->after('status', function ($table) {
                $table->boolean('dual_side_print')->default(false);
                $table->boolean('extra_copy')->default(false);
            });
        });
    }
};
