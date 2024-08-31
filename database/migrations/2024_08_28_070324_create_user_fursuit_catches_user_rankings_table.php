<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_catch_user_rankings', function (Blueprint $table) {
            $table->id();
            $table->integer('rank');
            $table->foreignIdFor(User::class)->unique()->constrained()->cascadeOnDelete();
            $table->integer('score');
            $table->integer('score_till_next');
            $table->integer('others_behind');
            $table->timestamp('score_reached_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_catch_user_rankings');
    }
};
