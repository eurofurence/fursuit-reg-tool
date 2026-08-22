<?php

namespace App\Http\Requests\Manage;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * The payload of `remindPickupBulk`.
 *
 * Authorised here rather than in the controller body for the reason BadgePrintRequest gives: a
 * FormRequest runs before the action, so an unauthorised call answers 403 rather than a 422 about
 * its payload. Mailing attendees is desk work, so it is the same `manage-admin` gate the print and
 * bulk-status endpoints are behind (docs/admin/roles.md).
 *
 * There is nothing to configure, so `ids` is the whole payload. What is refused - collected badges,
 * badges with no attendee - is refused in the controller against the record, not here: the operator
 * ticks rows on a list, and which of those rows are still worth a mail is a question about the
 * badges, not about the request.
 */
class BadgePickupReminderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage-admin');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ];
    }
}
