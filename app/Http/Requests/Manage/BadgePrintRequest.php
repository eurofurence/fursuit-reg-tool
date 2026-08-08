<?php

namespace App\Http\Requests\Manage;

use App\Http\Controllers\Manage\BadgePrintController;
use App\Models\Badge\Badge;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The payload of `printBadgeBulk` (audit 4.2, bulk action 1).
 *
 * Authorisation happens here rather than in the controller body, because a FormRequest
 * validates before the action runs: gating in the controller would answer an
 * unauthorised print with a 422 about its payload instead of a 403. It is the same
 * `viewAny` question the row action asks, for the reason BadgePrintController's docblock
 * gives.
 *
 * `printer_id` is `->required()` on the Filament select, and the values accepted here are
 * that select's own option set: active printers of type Badge. Read from
 * `BadgePrintController::printerIds()`, the one list the modal is built from, so a
 * printer the picker offers can never be one the rules refuse - or the other way round,
 * which is the shape that matters: a deactivated printer must not be printable just
 * because a stale modal is still open.
 *
 * Nothing is written by validation. A refused request queues no card and creates no
 * batch; it comes back with errors and the selection intact.
 */
class BadgePrintRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Same question BadgePrintController::bulkAction() asks before offering the
        // button: queueing cards is admin work, not review work (docs/admin/roles.md).
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
            'printer_id' => ['required', 'integer', Rule::in(BadgePrintController::printerIds())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'printer_id.required' => 'Select a printer to send these badges to.',
            'printer_id.in' => 'That printer is not an active badge printer.',
        ];
    }
}
