<?php

use App\Domain\CatchEmAll\Models\SpecialCode;
use App\Models\EventUser;
use App\Support\Migrations\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (SchemaGuard::missingTable('user_special_catches')) {
            Schema::create('user_special_catches', function (Blueprint $table) {
                $table->id();
                $table->foreignIdFor(EventUser::class)->constrained()->restrictOnDelete();
                $table->foreignIdFor(SpecialCode::class)->nullable()->constrained()->nullOnDelete();
                $table->unsignedInteger('type');
                $table->timestamps();

                $table->index('event_user_id');
                $table->index('type');
                $table->unique(['event_user_id', 'special_code_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_special_catches');
    }
};
