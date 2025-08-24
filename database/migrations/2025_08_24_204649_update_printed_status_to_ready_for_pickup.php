<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Update any badges with 'printed' status to 'ready_for_pickup'
        DB::table('badges')
            ->where('status_fulfillment', 'printed')
            ->update(['status_fulfillment' => 'ready_for_pickup']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reverse the operation - update 'ready_for_pickup' back to 'printed'
        DB::table('badges')
            ->where('status_fulfillment', 'ready_for_pickup')
            ->update(['status_fulfillment' => 'printed']);
    }
};
