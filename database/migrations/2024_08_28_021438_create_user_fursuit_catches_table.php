<?php

use App\Models\Fursuit\Fursuit;
use App\Models\User;
use App\Models\Event;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_catches', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Event::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Fursuit::class)->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'fursuit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_catches');
    }
};
