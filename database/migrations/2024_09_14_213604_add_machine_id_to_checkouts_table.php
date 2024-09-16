<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('checkouts', function (Blueprint $table) {
            $table->foreignIdFor(\App\Models\Machine::class)->nullable()->after('cashier_id')->constrained()->nullOnDelete();
        });
    }
};
