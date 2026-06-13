<?php

use App\Support\Migrations\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (SchemaGuard::missingColumn('events', 'mass_printed_at')) {
                $table->dateTime('mass_printed_at')
                    ->useCurrent()
                    ->after('preorder_ends_at')
                    ->comment('Timestamp when the event badges are being mass printed');
            }
        });
    }
};
