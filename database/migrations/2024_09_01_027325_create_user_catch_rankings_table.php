<?php

use App\Models\Fursuit\Fursuit;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use PhpParser\Node\Expr\AssignOp\Coalesce;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_catch_rankings', function (Blueprint $table) {
            $table->integer('id');
            $table->integer('rank');
            $table->foreignIdFor(User::class)->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Fursuit::class)->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->integer('score');
            $table->integer('score_till_next');
            $table->integer('others_behind');
            $table->timestamp('score_reached_at')->nullable();
        });

        // Only add constraint for databases that support it (MySQL/PostgreSQL)
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement(
                'ALTER TABLE user_catch_rankings
                        ADD CONSTRAINT user_xor_fursuit_exist
                        CHECK ((user_id IS NOT NULL OR fursuit_id IS NOT NULL)
                                   AND NOT (user_id IS NOT NULL AND fursuit_id IS NOT NULL));'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_catch_rankings');
    }
};
