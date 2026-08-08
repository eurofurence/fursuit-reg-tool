<?php

namespace App\Http\Requests\Manage;

use App\Models\Badge\State_Fulfillment\BadgeFulfillmentStatusState;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The payload of the badge list's bulk fulfillment write.
 *
 * Authorised here rather than in the controller body for the reason BadgePrintRequest
 * gives: a FormRequest runs before the action, so an unauthorised call answers 403 rather
 * than a 422 about its payload.
 *
 * The accepted statuses are the state machine's own registered names, read off
 * BadgeFulfillmentStatusState rather than typed out, so a state added or renamed there
 * cannot leave this list accepting something that no longer exists. The *transitions* are
 * deliberately not consulted - that is the whole point of this endpoint - but the target
 * still has to be a real state.
 */
class BadgeBulkStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Moving badges between fulfillment states is desk work, not review work, and this
        // particular door skips the state machine - so it is the same admin-only gate the
        // single-badge PUT is behind. See docs/admin/roles.md.
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
            'status_fulfillment' => ['required', 'string', Rule::in(self::statuses())],
        ];
    }

    /**
     * The registered fulfillment state names.
     *
     * @return array<int, string>
     */
    public static function statuses(): array
    {
        return BadgeFulfillmentStatusState::getStateMapping()->keys()->all();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status_fulfillment.required' => 'Choose the fulfillment status to set.',
            'status_fulfillment.in' => 'That is not a fulfillment status.',
        ];
    }
}
