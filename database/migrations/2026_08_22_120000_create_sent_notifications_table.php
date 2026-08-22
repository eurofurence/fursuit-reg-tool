<?php

use App\Support\Migrations\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every attendee-facing notification we have actually sent, one row per delivery.
 *
 * The desk gets asked "did they hear from us" constantly - about a rejection, about a badge
 * waiting at the counter - and until now the only answer was the mail server's log, which
 * nobody working the desk can read. This is that answer, on the record the question is about.
 *
 * Written from the framework's own NotificationSent event rather than from each notification,
 * so a notification added later is logged without anybody remembering to log it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (SchemaGuard::missingTable('sent_notifications')) {
            Schema::create('sent_notifications', function (Blueprint $table) {
                $table->id();

                // Morphs rather than a user_id, because a notification is addressed to a
                // notifiable and nothing guarantees that stays User. Every row today is a
                // user; the column is what keeps that from being an assumption.
                $table->morphs('notifiable');

                // The class, so the panel can label a row without the sender storing a label,
                // and the channel, so a future push or database notification is not silently
                // filed as an email.
                $table->string('notification');
                $table->string('channel');

                // What the recipient saw in their inbox. Nullable: a non-mail channel has no
                // subject, and a mail whose subject cannot be recovered is still worth the row.
                $table->string('subject')->nullable();

                // The record the notification was about - a badge, a fursuit - when the
                // notification carries one, so the panel can link back to it.
                $table->nullableMorphs('subject_model');

                $table->timestamp('sent_at');
                $table->timestamps();

                $table->index(['notifiable_type', 'notifiable_id', 'sent_at'], 'sent_notifications_recipient_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sent_notifications');
    }
};
