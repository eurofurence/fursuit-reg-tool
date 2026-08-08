<?php

use App\Models\Badge\Badge;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Badge::where('status', 'ready_for_pickup')->where('total', '>', 0)->update(['status' => 'unpaid']);
    }
};
