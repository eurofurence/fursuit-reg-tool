<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->foreignIdFor(\App\Models\SumUpReader::class, 'sumup_reader_id')->after('tse_client_id')->nullable()->constrained()->after('tse_client_id')->nullOnDelete();
        });
    }
};
