<?php

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
        Schema::table('event_users', function (Blueprint $table) {
            // Critical: AttendeeController lookup performance
            // Query: EventUser::where('attendee_id', $attendeeId)->where('event_id', $activeEvent->id)
            $table->index(['attendee_id', 'event_id'], 'idx_event_users_attendee_event_lookup');
        });

        Schema::table('fursuits', function (Blueprint $table) {
            // Critical: Gallery base query performance
            // Query: ->where('status', 'approved')->where('published', true)->whereNotNull('image')
            $table->index(['status', 'published', 'image'], 'idx_fursuits_gallery_base_filter');

            // High: Gallery event filtering
            // Query: ->where('event_id', $eventFilter)->where('status', 'approved')->where('published', true)
            $table->index(['event_id', 'status', 'published'], 'idx_fursuits_event_status_published');

            // High: Gallery name search and sorting
            // Query: ->where('fursuits.name', 'LIKE', $term)->orderBy('name')
            $table->index(['name'], 'idx_fursuits_name_search_sort');
        });

        Schema::table('transactions', function (Blueprint $table) {
            // Medium: Wallet transaction queries in AttendeeController
            // Query: ->where('amount', '<', 0)->orWhere('amount', '>', 0)
            $table->index(['amount'], 'idx_transactions_amount_filter');
        });

        Schema::table('events', function (Blueprint $table) {
            // Low: Event::getActiveEvent() performance
            // Query: self::latest('starts_at')->first()
            $table->index(['starts_at'], 'idx_events_starts_at_sorting');
        });

        // Additional indexes for related tables that support the main queries
        Schema::table('badges', function (Blueprint $table) {
            // Medium: Badge queries in AttendeeController
            // Query: ->whereHas('fursuit', function ($query) { $query->where('status', '!=', Rejected::$name); })
            $table->index(['fursuit_id', 'status_fulfillment'], 'idx_badges_fursuit_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('event_users', function (Blueprint $table) {
            $table->dropIndex('idx_event_users_attendee_event_lookup');
        });

        Schema::table('fursuits', function (Blueprint $table) {
            $table->dropIndex('idx_fursuits_gallery_base_filter');
            $table->dropIndex('idx_fursuits_event_status_published');
            $table->dropIndex('idx_fursuits_name_search_sort');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('idx_transactions_amount_filter');
        });

        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex('idx_events_starts_at_sorting');
        });

        Schema::table('badges', function (Blueprint $table) {
            $table->dropIndex('idx_badges_fursuit_status');
        });
    }
};
