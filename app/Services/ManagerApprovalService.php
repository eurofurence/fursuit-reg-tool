<?php

namespace App\Services;

use App\Models\RfidTag;
use App\Models\Staff;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Second-person authorisation for the till.
 *
 * A few POS actions change money after the fact - overriding what a badge costs.
 * Those need a manager, which is a flag on the Staff row (`is_manager`), not a
 * separate login: the cashier stays signed in and a manager reaches over to
 * approve with their own PIN or by scanning their RFID tag.
 *
 * The same credential pair the login screen accepts is accepted here, so a
 * manager needs to remember nothing extra. A manager already signed in at the
 * till approves their own action without typing anything.
 */
class ManagerApprovalService
{
    /**
     * Attempts allowed per machine before the approval prompt locks out. A PIN is
     * six digits and this endpoint would otherwise be an oracle for guessing one.
     */
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 300;

    /**
     * Whether the staff member currently signed in at this till may approve on
     * their own. Used by the UI to skip the prompt entirely.
     */
    public static function signedInStaffIsManager(): bool
    {
        $staff = auth('machine-user')->user();

        return $staff instanceof Staff && $staff->isManager();
    }

    /**
     * Resolve who is approving this action, or fail validation.
     *
     * @param  string|null  $code  A manager PIN or the content of a scanned RFID tag.
     *                             Ignored when the signed-in staff member is a manager.
     * @param  string  $field  Which request field the error is attached to.
     *
     * @throws ValidationException
     */
    public static function approve(?string $code, string $field = 'manager_code'): Staff
    {
        $signedIn = auth('machine-user')->user();

        if ($signedIn instanceof Staff && $signedIn->isManager()) {
            return $signedIn;
        }

        $code = trim((string) $code);

        if ($code === '') {
            throw ValidationException::withMessages([
                $field => 'A manager PIN or RFID scan is required for this change.',
            ]);
        }

        $key = self::throttleKey();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                $field => 'Too many failed attempts. Try again in '
                    .RateLimiter::availableIn($key).' seconds.',
            ]);
        }

        $manager = self::findManagerByCode($code);

        if (! $manager) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            throw ValidationException::withMessages([
                $field => 'Not a manager PIN or RFID badge.',
            ]);
        }

        RateLimiter::clear($key);

        return $manager;
    }

    /**
     * PIN first, then RFID: the two namespaces do not overlap (a PIN is six digits,
     * a tag is at least eight), so the caller never has to say which one it sent.
     * Setup codes are deliberately not accepted - an account that has not finished
     * setup has no PIN of its own yet.
     */
    private static function findManagerByCode(string $code): ?Staff
    {
        $staff = Staff::active()->managers()->where('pin_code', $code)->first();

        if ($staff) {
            return $staff;
        }

        $tag = RfidTag::active()->where('content', $code)->with('staff')->first();

        if ($tag && $tag->staff && $tag->staff->isManager()) {
            return $tag->staff;
        }

        return null;
    }

    private static function throttleKey(): string
    {
        return 'manager-approval:'.(auth('machine')->id() ?? 'unknown');
    }
}
