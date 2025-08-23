<?php

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
        Schema::table('print_jobs', function (Blueprint $table) {
            $table->unsignedBigInteger('retry_of')->nullable()->after('last_qz_message');
            $table->foreign('retry_of')->references('id')->on('print_jobs')->onDelete('set null');
            $table->index('retry_of');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('print_jobs', function (Blueprint $table) {
            $table->dropForeign(['retry_of']);
            $table->dropIndex(['retry_of']);
            $table->dropColumn('retry_of');
        });
    }
};
