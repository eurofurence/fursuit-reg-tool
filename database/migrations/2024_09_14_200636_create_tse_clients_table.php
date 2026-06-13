<?php

use App\Support\Migrations\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tse_clients')) {
            Schema::create('tse_clients', function (Blueprint $table) {
                $table->id();
                $table->string('remote_id');
                $table->string('serial_number');
                $table->string('state'); // REGISTERED, DEREGISTERED
                $table->timestamps();
            });
        }
        if (SchemaGuard::missingColumn('machines', 'tse_client_id')) {
            Schema::table('machines', function (Blueprint $table) {
                $table->foreignIdFor(\App\Domain\Checkout\Models\TseClient::class)->nullable()->after('receipt_printer_id')->constrained()->nullOnDelete();
            });
        }
    }
};
