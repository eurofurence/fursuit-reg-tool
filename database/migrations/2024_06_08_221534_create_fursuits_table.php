<?php

use App\Models\Species;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fursuits', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Species::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(\App\Models\Event::class)->constrained()->cascadeOnDelete(); // When fursuit was created, can be updated to current year at any time
            $table->string('status');
            $table->string('name');
            $table->string('image');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fursuits');
    }
};
