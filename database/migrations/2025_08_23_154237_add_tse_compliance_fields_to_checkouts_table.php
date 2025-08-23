<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkouts', function (Blueprint $table) {
            // Add TSE compliance fields as per KassenSichV requirements
            $table->string('tse_serial_number')->nullable();
            $table->string('tse_transaction_number')->nullable();
            $table->string('tse_signature_counter')->nullable();
            $table->text('tse_start_signature')->nullable();
            $table->text('tse_end_signature')->nullable();
            $table->timestamp('tse_timestamp')->nullable();
            $table->string('tse_process_type')->nullable(); // e.g., 'Kassenbeleg-V1'
            $table->string('tse_process_data')->nullable(); // Process data for audit
        });
    }

    public function down(): void
    {
        Schema::table('checkouts', function (Blueprint $table) {
            $table->dropColumn([
                'tse_serial_number',
                'tse_transaction_number',
                'tse_signature_counter',
                'tse_start_signature',
                'tse_end_signature',
                'tse_timestamp',
                'tse_process_type',
                'tse_process_data'
            ]);
        });
    }
};