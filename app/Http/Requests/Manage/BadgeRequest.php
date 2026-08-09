<?php

namespace App\Http\Requests\Manage;

use App\Http\Controllers\Manage\BadgeController;
use App\Models\Badge\Badge;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Update for /admin/badges/{badge}. There is no store: the create page is not ported
 *.
 *
 * The rule set is deliberately two fields long, and that is the whole point of it.
 * the old badge list's form carried fourteen, twelve of them disabled, and the old panel's edit
 * page writes raw form state straight to the model. The one enabled money field rendered
 * `number_format($state / 100, 2)` on read with no inverse on write, so saving an
 * unchanged badge stored "3.00" in a cents column. Nothing here accepts a money
 * field at all, so no /admin write path can put a euro string into a cents column,
 * whatever the request carries: `Badge` is `$guarded = []`, so the request has to be the
 * thing that refuses it.
 *
 * The two statuses that do survive are validated against what the state machine allows
 * from the badge's current state, not against the full state list. The old panel selects
 * offered every state unconditionally and wrote it through the cast, so admin could put a
 * badge somewhere no transition leads.
 */
class BadgeRequest extends FormRequest
{
    /**
     * Authorized here rather than in the controller so an operator who may not write
     * never gets validation feedback on a form they cannot submit.
     */
    public function authorize(): bool
    {
        $badge = $this->route('badge');

        return $badge instanceof Badge && Gate::allows('update', $badge);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $badge = $this->route('badge');

        return [
            // the old panel had both Selects ->required().
            'status_fulfillment' => [
                'required',
                'string',
                Rule::in($this->allowed(BadgeController::fulfillmentOptions($badge))),
            ],
            'status_payment' => [
                'required',
                'string',
                Rule::in($this->allowed(BadgeController::paymentOptions($badge))),
            ],
        ];
    }

    /**
     * the old panel's own labels for these two fields, so a validation message names them the
     * way the form does.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'status_fulfillment' => 'Fulfillment Status',
            'status_payment' => 'Payment Status',
        ];
    }

    /**
     * @param  array<int, array{value: string, label: string}>  $options
     * @return array<int, string>
     */
    private function allowed(array $options): array
    {
        return array_column($options, 'value');
    }
}
