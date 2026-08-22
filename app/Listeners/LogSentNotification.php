<?php

namespace App\Listeners;

use App\Models\SentNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\SentMessage;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Log;
use ReflectionObject;
use Throwable;

/**
 * Write one row per delivered notification, so the panel can answer "did we tell them".
 *
 * Hooked to the framework's own NotificationSent rather than to each notification class: this fires
 * after a channel reports success, for every notification the app has and every one it grows, and
 * nobody has to remember to log anything. The cost is that what is worth recording has to be
 * recovered from the notification afterwards, which is what the two helpers below do.
 *
 * Registered by nothing: Laravel discovers listeners in app/Listeners from the event they type-hint,
 * so this class is wired up by living here. Adding an explicit Event::listen() beside it registers
 * it a second time and every notification is then logged twice, which is exactly what happened the
 * first time this was written.
 *
 * Nothing here may break a send. A notification that has already gone out is not undeliverable
 * because we failed to file it, so every failure is swallowed into the log rather than thrown: the
 * mail is the product, this is the paperwork.
 */
class LogSentNotification
{
    public function handle(NotificationSent $event): void
    {
        try {
            $notifiable = $event->notifiable;

            if (! $notifiable instanceof Model) {
                return;
            }

            $subjectModel = $this->subjectModel($event);

            SentNotification::create([
                'notifiable_type' => $notifiable->getMorphClass(),
                'notifiable_id' => $notifiable->getKey(),
                'notification' => $event->notification::class,
                'channel' => $event->channel,
                'subject' => $this->subject($event),
                'subject_model_type' => $subjectModel?->getMorphClass(),
                'subject_model_id' => $subjectModel?->getKey(),
                'sent_at' => now(),
            ]);
        } catch (Throwable $exception) {
            Log::warning('Could not log a sent notification', [
                'notification' => $event->notification::class,
                'channel' => $event->channel,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * What the recipient saw in their inbox.
     *
     * Read off the message the mailer actually sent, which is the only copy that is certainly the
     * one delivered. Re-rendering the notification only happens when there is no such message,
     * because the re-rendered subject is merely likely to match: the fursuit could have been
     * renamed between the send and this line.
     */
    private function subject(NotificationSent $event): ?string
    {
        $response = $event->response;

        if ($response instanceof SentMessage) {
            $subject = $response->getOriginalMessage()->getSubject();

            if ($subject !== null && $subject !== '') {
                return $subject;
            }
        }

        // Not every mailer hands back a message - a faked one does not, and neither does a
        // channel that is not mail at all. Asking the notification for its subject is a second
        // best rather than the default: it is the subject this notification would render now,
        // which is not certainly the one that was delivered.
        if ($event->channel === 'mail' && method_exists($event->notification, 'toMail')) {
            $message = $event->notification->toMail($event->notifiable);

            return property_exists($message, 'subject') && $message->subject !== ''
                ? $message->subject
                : null;
        }

        return null;
    }

    /**
     * The record the notification is about, when it carries one.
     *
     * Every badge and fursuit notification takes its record as a constructor-promoted public
     * property, so the first Eloquent model among them is the thing the mail is about. Read by
     * reflection rather than by asking each notification for it: an interface would have to be
     * implemented on every existing class and remembered on every new one, and the point of this
     * listener is that nothing has to be remembered.
     */
    private function subjectModel(NotificationSent $event): ?Model
    {
        foreach ((new ReflectionObject($event->notification))->getProperties() as $property) {
            if (! $property->isPublic() || $property->isStatic()) {
                continue;
            }

            $value = $property->getValue($event->notification);

            if ($value instanceof Model && $value->exists) {
                return $value;
            }
        }

        return null;
    }
}
