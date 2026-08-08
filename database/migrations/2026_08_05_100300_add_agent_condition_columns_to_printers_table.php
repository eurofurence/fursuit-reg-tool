<?php

use App\Support\Migrations\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Physical printer condition, uploaded by the print agent.
     *
     * The printer is on a private LAN and Laravel is on the internet, so the
     * server cannot read the hardware itself. The agent polls the printer over
     * SNMP, reduces what it finds to a PrinterConditionEnum case and posts it
     * here. That is what the POS shows staff when the printer jams, runs out of
     * ribbon or empties its card hopper.
     *
     * Distinct from the existing `status` column, which tracks what the queue is
     * doing rather than what the hardware is doing.
     */
    public function up(): void
    {
        Schema::table('printers', function (Blueprint $table) {
            if (SchemaGuard::missingColumn('printers', 'condition')) {
                $table->string('condition')->default('unknown')->after('status');
            }

            if (SchemaGuard::missingColumn('printers', 'condition_message')) {
                $table->text('condition_message')->nullable()->after('condition');
            }

            if (SchemaGuard::missingColumn('printers', 'condition_reported_at')) {
                $table->timestamp('condition_reported_at')->nullable()->after('condition_message');
            }

            // Cards left on the current ribbon, straight from the standard
            // Printer MIB supply table, so staff can swap consumables before the
            // queue stops rather than after.
            if (SchemaGuard::missingColumn('printers', 'cards_remaining')) {
                $table->unsignedInteger('cards_remaining')->nullable()->after('condition_reported_at');
            }

            if (SchemaGuard::missingColumn('printers', 'cards_capacity')) {
                $table->unsignedInteger('cards_capacity')->nullable()->after('cards_remaining');
            }

            // Full SNMP reading behind the summarised condition, kept for
            // diagnosing states the agent has not learned to classify yet.
            if (SchemaGuard::missingColumn('printers', 'condition_raw')) {
                $table->json('condition_raw')->nullable()->after('cards_capacity');
            }
        });

        Schema::table('printers', function (Blueprint $table) {
            if (! SchemaGuard::hasIndex('printers', ['condition'])) {
                $table->index('condition');
            }
        });
    }

    public function down(): void
    {
        Schema::table('printers', function (Blueprint $table) {
            if (SchemaGuard::hasIndex('printers', ['condition'])) {
                $table->dropIndex(['condition']);
            }
        });

        Schema::table('printers', function (Blueprint $table) {
            $columns = [
                'condition', 'condition_message', 'condition_reported_at',
                'cards_remaining', 'cards_capacity', 'condition_raw',
            ];

            foreach ($columns as $column) {
                if (SchemaGuard::hasColumn('printers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
