<?php

use App\Models\Fursuit\Fursuit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('badges', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Fursuit::class)->constrained()->cascadeOnDelete();
            $table->string('status'); // pending, printed, ready_for_pickup, picked_up

            // Money Start
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->decimal('tax_rate')->default(0);
            $table->unsignedBigInteger('tax')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            // Money End

            $table->string('pickup_location')->nullable();
            $table->dateTime('ready_for_pickup_at')->nullable();
            $table->dateTime('picked_up_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('badges');
    }
};
