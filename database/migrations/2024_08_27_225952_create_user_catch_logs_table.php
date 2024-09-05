<?php

use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_catch_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Event::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->string('catch_code', 255);
            $table->boolean('is_successful');
            $table->boolean('already_caught');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_catch_logs');
    }
};
