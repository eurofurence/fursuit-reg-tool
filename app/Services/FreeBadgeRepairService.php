<?php

namespace App\Services;

use App\Models\Badge\Badge;
use App\Models\Badge\State_Payment\Paid;
use App\Models\Event;
use App\Models\EventUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Detects and repairs badges that were wrongly charged the badge fee even though the owner had
 * unused prepaid-badge entitlement (event_users.prepaid_badges) that should have made them free.
 *
 * The wrong charge is reversed by marking the badge free and crediting the originally charged
 * amount back to the user's wallet. Both the wallet movement (transactions table) and an
 * activity_log entry are recorded. See docs/bugfix-03-fix.md.
 */
class FreeBadgeRepairService
{
    /**
     * Build a non-mutating report of the badges that would be repaired for the given event.
     *
     * @return array{
     *     event_id: int|null,
     *     event_name: string|null,
     *     affected_user_count: int,
     *     affected_badge_count: int,
     *     total_refund_cents: int,
     *     rows: array<int, array<string, mixed>>
     * }
     */
    public function preview(?Event $event): array
    {
        if ($event === null) {
            return $this->emptyReport(null, null);
        }

        $rows = [];
        $userCount = 0;
        $refund = 0;

        foreach ($this->eventUsersWithPrepaid($event) as $eventUser) {
            $analysis = $this->analyseUser($eventUser, $event);
            $badgesToFix = $analysis['badges_to_fix'];

            if ($badgesToFix->isEmpty()) {
                continue;
            }

            $userCount++;

            foreach ($badgesToFix as $badge) {
                $refund += (int) $badge->total;
                $rows[] = [
                    'badge_id' => $badge->id,
                    'custom_id' => $badge->custom_id,
                    'image' => $badge->fursuit?->image,
                    'fursuit' => $badge->fursuit?->name,
                    'species' => $badge->fursuit?->species?->name,
                    'owner' => $eventUser->user?->name,
                    'user_id' => $eventUser->user_id,
                    'prepaid_badges' => (int) $eventUser->prepaid_badges,
                    'badges_total' => $analysis['badges_total'],
                    'should_be_free' => $analysis['should_be_free'],
                    'should_be_paid' => $analysis['should_be_paid'],
                    'current_total' => (int) $badge->total,
                ];
            }
        }

        return [
            'event_id' => $event->id,
            'event_name' => $event->name,
            'affected_user_count' => $userCount,
            'affected_badge_count' => count($rows),
            'total_refund_cents' => $refund,
            'rows' => $rows,
        ];
    }

    /**
     * Apply the repair atomically.
     *
     * @return array{
     *     success: bool,
     *     error: string|null,
     *     event_id: int|null,
     *     fixed_user_count: int,
     *     fixed_badge_count: int,
     *     total_refunded_cents: int
     * }
     */
    public function repair(?Event $event, ?User $admin = null): array
    {
        if ($event === null) {
            return [
                'success' => false,
                'error' => 'No active event.',
                'event_id' => null,
                'fixed_user_count' => 0,
                'fixed_badge_count' => 0,
                'total_refunded_cents' => 0,
            ];
        }

        $fixedBadges = 0;
        $fixedUsers = 0;
        $refunded = 0;

        try {
            DB::transaction(function () use ($event, $admin, &$fixedBadges, &$fixedUsers, &$refunded) {
                foreach ($this->eventUsersWithPrepaid($event, lock: true) as $eventUser) {
                    $badgesToFix = $this->analyseUser($eventUser, $event)['badges_to_fix'];

                    if ($badgesToFix->isEmpty()) {
                        continue;
                    }

                    $user = $eventUser->user;
                    $userFixed = 0;

                    foreach ($badgesToFix as $badge) {
                        $oldTotal = (int) $badge->total;
                        $oldSubtotal = (int) $badge->subtotal;
                        $oldTax = (int) $badge->tax;

                        $badge->is_free_badge = true;
                        $badge->total = 0;
                        $badge->subtotal = 0;
                        $badge->tax = 0;
                        $badge->status_payment = Paid::class;
                        $badge->paid_at = now();
                        $badge->saveQuietly();

                        // Credit the wrongly charged amount back to the user's wallet
                        // (reverses the original forcePay debit). Recorded in transactions.
                        if ($oldTotal > 0 && $user) {
                            $user->deposit($oldTotal, [
                                'title' => 'Prepaid badge fee correction',
                                'description' => "Refund of wrongly charged fee for badge #{$badge->id}",
                                'event_id' => $event->id,
                                'badge_id' => $badge->id,
                                'reason' => 'free_badge_fix',
                            ]);
                            $refunded += $oldTotal;
                        }

                        // Audit trail in activity_log.
                        activity()
                            ->performedOn($badge)
                            ->causedBy($admin)
                            ->withProperties([
                                'reason' => 'free_badge_fix',
                                'event_id' => $event->id,
                                'prepaid_badges' => (int) $eventUser->prepaid_badges,
                                'old_total' => $oldTotal,
                                'old_subtotal' => $oldSubtotal,
                                'old_tax' => $oldTax,
                                'new_total' => 0,
                                'refunded_cents' => $oldTotal,
                            ])
                            ->log('Corrected wrongly charged prepaid badge to free');

                        $fixedBadges++;
                        $userFixed++;
                    }

                    if ($userFixed > 0) {
                        $fixedUsers++;
                    }
                }
            });
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'event_id' => $event->id,
                'fixed_user_count' => 0,
                'fixed_badge_count' => 0,
                'total_refunded_cents' => 0,
            ];
        }

        return [
            'success' => true,
            'error' => null,
            'event_id' => $event->id,
            'fixed_user_count' => $fixedUsers,
            'fixed_badge_count' => $fixedBadges,
            'total_refunded_cents' => $refunded,
        ];
    }

    /**
     * Analyse a single event user: how many badges they have, how many should be free vs paid,
     * and which paid main badges must be converted to free.
     *
     * @return array{
     *     badges_total: int,
     *     should_be_free: int,
     *     should_be_paid: int,
     *     badges_to_fix: \Illuminate\Support\Collection<int, Badge>
     * }
     */
    protected function analyseUser(EventUser $eventUser, Event $event): array
    {
        $allowed = (int) $eventUser->prepaid_badges;

        $badges = Badge::query()
            ->whereHas('fursuit', function ($query) use ($eventUser, $event) {
                $query->where('user_id', $eventUser->user_id)
                    ->where('event_id', $event->id);
            })
            ->with(['fursuit.species'])
            ->get();

        // Spare copies (extra_copy_of !== null) are always separately paid and never free.
        $mainBadges = $badges->whereNull('extra_copy_of');
        $freeMainCount = $mainBadges->where('is_free_badge', true)->count();
        $paidMain = $mainBadges->where('is_free_badge', false)->sortBy('id')->values();

        $badgesTotal = $badges->count();
        $shouldBeFree = min($allowed, $mainBadges->count());
        $shouldBePaid = $badgesTotal - $shouldBeFree;

        $toConvert = max(0, min($allowed - $freeMainCount, $paidMain->count()));

        return [
            'badges_total' => $badgesTotal,
            'should_be_free' => $shouldBeFree,
            'should_be_paid' => $shouldBePaid,
            'badges_to_fix' => $paidMain->take($toConvert),
        ];
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, EventUser>
     */
    protected function eventUsersWithPrepaid(Event $event, bool $lock = false)
    {
        $query = EventUser::with('user')
            ->where('event_id', $event->id)
            ->where('prepaid_badges', '>', 0);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    /**
     * Build a temporary, displayable URL for a fursuit image (best effort; null on failure).
     */
    public function imageUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        try {
            return Storage::disk('s3')->temporaryUrl($path, now()->addMinutes(15));
        } catch (\Throwable $e) {
            try {
                return Storage::disk('s3')->url($path);
            } catch (\Throwable $e2) {
                return null;
            }
        }
    }

    private function emptyReport(?int $eventId, ?string $eventName): array
    {
        return [
            'event_id' => $eventId,
            'event_name' => $eventName,
            'affected_user_count' => 0,
            'affected_badge_count' => 0,
            'total_refund_cents' => 0,
            'rows' => [],
        ];
    }
}
