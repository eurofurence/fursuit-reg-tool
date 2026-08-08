<?php

namespace App\Http\Requests\Manage;

use App\Http\Controllers\Manage\FursuitController;
use App\Models\Fursuit\Fursuit;
use App\Models\Fursuit\States\Approved;
use App\Models\Fursuit\States\FursuitStatusState;
use App\Models\Fursuit\States\Pending;
use App\Models\Fursuit\States\Rejected;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The fursuit edit form. There is no create counterpart: FursuitPolicy::create() returns
 * false and stays false, so no create route exists.
 *
 * Authorisation happens here rather than in the controller body, because a FormRequest
 * validates before the action runs: gating in the controller would answer an
 * unauthorised write with a 422 about its payload instead of a 403.
 *
 * Three fields the old panel form carried are deliberately absent from the payload:
 *
 *  - `status` is still accepted, but as a transition name rather than a value to write.
 *    payload() never contains it; the controller runs transitionTo() instead
 *   .
 *  - `approved_at` and `rejected_at` are gone entirely. They were hand-editable
 *    DateTimePickers that could contradict `status`; the transitions own them now.
 */
class FursuitRequest extends FormRequest
{
    public function authorize(): bool
    {
        $fursuit = $this->route('fursuit');

        return $fursuit instanceof Fursuit && Gate::allows('update', $fursuit);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'species_id' => ['required', 'integer', 'exists:species,id'],
            /*
             * A relation select rather than the free numeric TextInput it replaces
             *, so the id has to name an event that exists instead of any
             * number the operator typed.
             */
            'event_id' => ['required', 'integer', 'exists:events,id'],
            'name' => ['required', 'string', 'max:255'],
            /*
             * The stored object key on s3, produced by POST /admin/uploads with purpose
             * `fursuit_image`. The old panel FileUpload had no ->disk(), so it wrote to
             * the default filesystem disk while the table, the infolist and DbService
             * all read from s3. Required, as it is today.
             */
            'image' => ['required', 'string', 'max:2048'],
            'published' => ['required', 'boolean'],
            'catch_em_all' => ['required', 'boolean'],
            /*
             * Only the transitions the machine allows from where this record stands.
             * Absent, or equal to the current state, means "leave the status alone",
             * which is what saving an unrelated edit has to mean.
             */
            'status' => ['nullable', 'string', Rule::in($this->transitionNames())],
            'rejection_reason' => ['required_if:status,rejected', 'nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'user_id' => 'User',
            'species_id' => 'Species',
            'event_id' => 'Event',
            'catch_em_all' => 'Catch em all',
            'rejection_reason' => 'Rejection Reason',
        ];
    }

    /**
     * The attributes the update writes directly. `status` is never among them.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $validated = $this->validated();

        return [
            'user_id' => (int) $validated['user_id'],
            'species_id' => (int) $validated['species_id'],
            'event_id' => (int) $validated['event_id'],
            'name' => $validated['name'],
            'image' => $validated['image'],
            'published' => (bool) $validated['published'],
            'catch_em_all' => (bool) $validated['catch_em_all'],
        ];
    }

    /**
     * The state class to transition to, or null when the status is unchanged.
     *
     * @return class-string<FursuitStatusState>|null
     */
    public function transitionTarget(): ?string
    {
        $fursuit = $this->route('fursuit');
        $target = $this->validated()['status'] ?? null;

        if ($target === null || $target === '') {
            return null;
        }

        // Re-submitting the state the record is already in is not a transition. The
        // machine would refuse it, and an unrelated edit that leaves the picker where it
        // found it has to save.
        if ($fursuit instanceof Fursuit && $target === $fursuit->status::$name) {
            return null;
        }

        return match ($target) {
            'approved' => Approved::class,
            'rejected' => Rejected::class,
            'pending' => Pending::class,
            default => null,
        };
    }

    /**
     * The reason mailed to the owner when the form rejects a fursuit.
     */
    public function rejectionReason(): string
    {
        return (string) ($this->validated()['rejection_reason'] ?? '');
    }

    /**
     * The values the status picker may carry: the transitions the machine allows from
     * here, read from the same helper the picker is built from so the two cannot
     * disagree, plus the record's current state, which is the picker's own resting
     * position and means "no change".
     *
     * @return array<int, string>
     */
    private function transitionNames(): array
    {
        $fursuit = $this->route('fursuit');
        $user = $this->user();

        if (! $fursuit instanceof Fursuit || $user === null) {
            return [];
        }

        return [
            $fursuit->status::$name,
            ...FursuitController::allowedTransitions($fursuit, $user),
        ];
    }
}
