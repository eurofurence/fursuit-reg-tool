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
            $table->after('status', function ($table) {
                if (SchemaGuard::missingColumn('badges', 'dual_side_print')) {
                    $table->boolean('dual_side_print')->default(false);
                }
                if (SchemaGuard::missingColumn('badges', 'extra_copy')) {
                    $table->boolean('extra_copy')->default(false);
                }
            });
        });
    }
};
