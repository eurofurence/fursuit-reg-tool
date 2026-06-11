<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Promote the user with this remote_id to admin if they exist. update()
     * affects 0 rows when no user matches, so a missing user is simply ignored,
     * and re-running is a harmless no-op. No down() is required for this change.
     */
    public function up(): void
    {
        DB::table('users')
            ->where('remote_id', 'ZGD30K38PZ54LXMY')
            ->update(['is_admin' => true]);
    }
};
