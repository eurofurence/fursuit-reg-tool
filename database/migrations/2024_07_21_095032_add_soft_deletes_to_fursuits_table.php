<?php

use App\Support\Migrations\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fursuits', function (Blueprint $table) {
            $table->after('rejected_at', function ($table) {
                if (SchemaGuard::missingColumn('fursuits', 'deleted_at')) {
                    $table->softDeletes();
                }
            });
        });
    }
};
