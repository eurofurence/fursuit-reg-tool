<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tse_clients', function (Blueprint $table) {
            $table->id();
            $table->string('remote_id');
            $table->string('serial_number');
            $table->string('state'); // REGISTERED, DEREGISTERED
            $table->timestamps();
        });
        Schema::table('machines', function (Blueprint $table) {
            $table->foreignIdFor(\App\Domain\Checkout\Models\TseClient::class)->nullable()->after('receipt_printer_id')->constrained()->nullOnDelete();
        });
    }
};
