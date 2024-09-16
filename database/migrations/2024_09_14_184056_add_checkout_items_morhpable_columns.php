<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('checkout_items', function (Blueprint $table) {
            $table->after('checkout_id', function ($table) {
                $table->morphs('payable');
            });
        });
    }
};
