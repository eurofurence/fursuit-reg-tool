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
            if (SchemaGuard::missingColumn('checkouts', 'payment_method_remote_id')) {
                $table->string('payment_method_remote_id')->nullable()->after('payment_method');
            }
        });
    }
};
