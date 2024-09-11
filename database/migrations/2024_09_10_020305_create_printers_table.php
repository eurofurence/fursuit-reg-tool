<?php

use App\Models\Machine;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('printers', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Machine::class)->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('default_paper_size')->nullable();
            $table->json('paper_sizes');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('printers');
    }
};
