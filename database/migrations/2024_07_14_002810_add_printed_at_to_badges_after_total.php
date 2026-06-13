<?php

use App\Support\Migrations\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('badges', function (Blueprint $table) {
            if (SchemaGuard::missingColumn('badges', 'printed_at')) {
                $table->dateTime('printed_at')->after('total')->nullable();
            }
        });
    }
};
