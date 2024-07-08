<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('badges', function (Blueprint $table) {
            $table->foreignIdFor(\App\Models\Badge\Badge::class, 'extra_copy_of')
                ->after('fursuit_id')
                ->nullable()
                ->constrained('badges')
                ->cascadeOnDelete();
        });
    }
};
