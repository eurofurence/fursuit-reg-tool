<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkouts', function (Blueprint $table) {
            $table->id();
            $table->string('status');
            $table->string('payment_method')->nullable();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('cashier_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('subtotal');
            $table->unsignedBigInteger('tax');
            $table->unsignedBigInteger('total');
            $table->string('signature')->nullable();
            $table->string('fiskaly_id')->nullable();
            $table->json('fiskaly_data');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkouts');
    }
};
