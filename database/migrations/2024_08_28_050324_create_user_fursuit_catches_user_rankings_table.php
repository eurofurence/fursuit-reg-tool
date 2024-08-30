<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_catch_user_rankings', function (Blueprint $table) {
            $table->integer('rank');
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->integer('catches');
            $table->integer('catches_till_next');
            $table->integer('users_behind');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_catch_user_rankings');
    }
};
