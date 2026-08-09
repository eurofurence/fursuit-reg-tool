<?php

use App\Support\Migrations\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a badge beyond the included one costs, per event.
 *
 * The fee was a constant in BadgeCalculationService, which meant a price change was a
 * deploy, and the front page and the FAQ both quote it. It belongs next to
 * `free_badge_deadline` and the order window: same kind of per-event policy, same people
 * deciding it, same screen to change it on.
 *
 * Cents, integer, because that is the unit every price in this system is stored and
 * calculated in (`badges.total`, `subtotal`, `tax`) and a float euro column would round
 * differently from the tax split that is derived from it.
 *
 * Not nullable, defaulting to 500: existing rows keep the 5,00 EUR they were being
 * charged, and an event that forgot to set a price charges the old one rather than
 * handing out free badges.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (SchemaGuard::missingColumn('events', 'badge_price_cents')) {
            Schema::table('events', function (Blueprint $table) {
                $table->unsignedInteger('badge_price_cents')
                    ->default(500)
                    ->after('free_badge_deadline');
            });
        }
    }

    public function down(): void
    {
        if (SchemaGuard::hasColumn('events', 'badge_price_cents')) {
            Schema::table('events', function (Blueprint $table) {
                $table->dropColumn('badge_price_cents');
            });
        }
    }
};
