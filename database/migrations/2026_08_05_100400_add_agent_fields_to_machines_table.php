<?php

use App\Support\Migrations\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Liveness for the native print agent, which replaces the QZ Tray browser
     * bridge. The agent calls in on its own schedule, so "when did we last hear
     * from it" is the only honest health signal we have.
     */
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            if (SchemaGuard::missingColumn('machines', 'agent_last_seen_at')) {
                $table->timestamp('agent_last_seen_at')->nullable();
            }

            if (SchemaGuard::missingColumn('machines', 'agent_version')) {
                $table->string('agent_version')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            foreach (['agent_last_seen_at', 'agent_version'] as $column) {
                if (SchemaGuard::hasColumn('machines', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
