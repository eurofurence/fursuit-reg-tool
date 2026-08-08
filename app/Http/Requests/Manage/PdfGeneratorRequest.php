<?php

namespace App\Http\Requests\Manage;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The query both PDF Generator downloads read (audit 5.1).
 *
 * One request for two routes, because the form is one form: the badge-list route reads
 * the badge-list half and the box-label route reads the box-label half, and every key is
 * optional so a route is never refused over a field it does not use. The five parity
 * notifications are not expressed here - "no event selected", "no data", an unparsable
 * range list, an empty range match and a missing box-label title are Filament
 * notifications with verbatim copy, and they stay toasts on the page rather than turning
 * into 422s (audit 5.1, notification table).
 *
 * What is expressed here is the shape. The three layout numbers were `->numeric()` in
 * Filament, which is a client-side hint and nothing more, so `columns=100000` reached
 * mPDF and asked it to lay out a hundred thousand table cells per row inside the web
 * request. They are integers with a ceiling now. The ceilings sit far above anything the
 * page offers (the defaults are 50 / 12 / 6) so no input an operator can reach through
 * the form is refused; they exist so an input nobody can reach through the form cannot
 * hang a worker either.
 *
 * `authorize()` is true on purpose: `can:access-manage` on the route group is the whole
 * guard for this page, exactly as the Filament page had no `canAccess()` and was open to
 * reviewers as well as admins (parity checklist line 83).
 *
 * Validation writes nothing, and neither does the request it guards. Generating a PDF is
 * a read.
 */
class PdfGeneratorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'payment_status' => ['nullable', 'string', Rule::in(['all', 'paid', 'unpaid'])],
            'badge_ranges' => ['nullable', 'string', 'max:1000'],
            'rows_per_column' => ['nullable', 'integer', 'min:1', 'max:500'],
            'columns' => ['nullable', 'integer', 'min:1', 'max:50'],
            'font_size' => ['nullable', 'integer', 'min:1', 'max:72'],
            'title' => ['nullable', 'string', 'max:255'],
            'subtitle' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rows_per_column.max' => 'A column holds at most 500 rows.',
            'columns.max' => 'A page holds at most 50 columns.',
            'font_size.max' => 'The font size is at most 72px.',
        ];
    }
}
