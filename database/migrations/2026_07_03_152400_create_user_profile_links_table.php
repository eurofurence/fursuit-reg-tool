<?php

use App\Models\UserProfile\UserProfile;
use App\Support\Migrations\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (SchemaGuard::missingTable('user_profile_links')) {
            Schema::create('user_profile_links', function (Blueprint $table) {
                $table->id();
                $table->foreignIdFor(UserProfile::class)->constrained()->cascadeOnDelete();
                $url = $table->string('url', 255)->index();

                // I hate MySQL
                if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                    $url->collation('utf8mb4_bin');
                }

                $table->unique(['user_profile_id', 'url']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_profile_links');
    }
};
