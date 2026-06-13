<?php

use App\Support\Migrations\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkouts', function (Blueprint $table) {
            // Add TSE compliance fields as per KassenSichV requirements
            if (SchemaGuard::missingColumn('checkouts', 'tse_serial_number')) {
                $table->string('tse_serial_number')->nullable();
            }
            if (SchemaGuard::missingColumn('checkouts', 'tse_transaction_number')) {
                $table->string('tse_transaction_number')->nullable();
            }
            if (SchemaGuard::missingColumn('checkouts', 'tse_signature_counter')) {
                $table->string('tse_signature_counter')->nullable();
            }
            if (SchemaGuard::missingColumn('checkouts', 'tse_start_signature')) {
                $table->text('tse_start_signature')->nullable();
            }
            if (SchemaGuard::missingColumn('checkouts', 'tse_end_signature')) {
                $table->text('tse_end_signature')->nullable();
            }
            if (SchemaGuard::missingColumn('checkouts', 'tse_timestamp')) {
                $table->timestamp('tse_timestamp')->nullable();
            }
            if (SchemaGuard::missingColumn('checkouts', 'tse_process_type')) {
                $table->string('tse_process_type')->nullable(); // e.g., 'Kassenbeleg-V1'
            }
            if (SchemaGuard::missingColumn('checkouts', 'tse_process_data')) {
                $table->string('tse_process_data')->nullable(); // Process data for audit
            }
        });
    }

    public function down(): void
    {
        Schema::table('checkouts', function (Blueprint $table) {
            if (SchemaGuard::hasColumn('checkouts', 'tse_serial_number')) {
                $table->dropColumn('tse_serial_number');
            }
            if (SchemaGuard::hasColumn('checkouts', 'tse_transaction_number')) {
                $table->dropColumn('tse_transaction_number');
            }
            if (SchemaGuard::hasColumn('checkouts', 'tse_signature_counter')) {
                $table->dropColumn('tse_signature_counter');
            }
            if (SchemaGuard::hasColumn('checkouts', 'tse_start_signature')) {
                $table->dropColumn('tse_start_signature');
            }
            if (SchemaGuard::hasColumn('checkouts', 'tse_end_signature')) {
                $table->dropColumn('tse_end_signature');
            }
            if (SchemaGuard::hasColumn('checkouts', 'tse_timestamp')) {
                $table->dropColumn('tse_timestamp');
            }
            if (SchemaGuard::hasColumn('checkouts', 'tse_process_type')) {
                $table->dropColumn('tse_process_type');
            }
            if (SchemaGuard::hasColumn('checkouts', 'tse_process_data')) {
                $table->dropColumn('tse_process_data');
            }
        });
    }
};
