<?php

use App\Domain\CatchEmAll\Enums\SpecialCodeType;
use App\Domain\CatchEmAll\Models\SpecialCode;
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
        if (SchemaGuard::missingColumn('special_codes', 'type')) {
            Schema::table('special_codes', function (Blueprint $table) {
                $table->string('type')->nullable()->after('class_name');
            });
        }

        if (SchemaGuard::hasColumn('special_codes', 'type')) {
            SpecialCode::query()
                ->where('class_name', 'App\\Domain\\CatchEmAll\\SpecialActions\\BugBountyAction')
                ->update(['type' => SpecialCodeType::BUG_BOUNTY->name]);

            SpecialCode::query()
                ->where('class_name', 'App\\Domain\\CatchEmAll\\SpecialActions\\CatchEmAllTeamAction')
                ->update(['type' => SpecialCodeType::CATCH_EM_ALL_TEAM->name]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (SchemaGuard::hasColumn('special_codes', 'type')) {
            Schema::table('special_codes', function (Blueprint $table) {
                $table->dropColumn('type');
            });
        }
    }
};
