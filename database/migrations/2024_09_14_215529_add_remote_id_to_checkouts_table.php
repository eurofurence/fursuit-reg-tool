<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkouts', function (Blueprint $table) {
            // string after id
            $table->string('remote_id')->after('id')->nullable();
            // add remote_rev_count
            $table->integer('remote_rev_count')->after('remote_id')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('checkouts', function (Blueprint $table) {
            $table->dropColumn('remote_id');
            $table->dropColumn('remote_rev_count');
        });
    }
};
