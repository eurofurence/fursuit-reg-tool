<?php

namespace App\Http\Requests\Manage;

use App\Domain\Printing\Models\Printer;
use App\Enum\PrintJobTypeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Create and update for /admin/printers.
 *
 * The rules are PrinterResource's form schema (audit 4.7), with two things settled that
 * the Filament form left open.
 *
 * `default_paper_size` is checked against the sizes this printer actually reported. The
 * Filament Select built its options from `collect($record->paper_sizes)` through a closure
 * that type-hinted a non-nullable `Printer $record`, so the create page threw a TypeError
 * before anyone could pick anything (plan 2.10 #7, landmine 27). On create there is no
 * record and therefore no size to choose: the field has to be empty, and it is enforced
 * here rather than trusted to the client.
 *
 * `paper_sizes` is not accepted at all. The field is disabled in the form because the
 * print agent owns it; leaving it out of the rules means a crafted request cannot
 * overwrite what the hardware reported.
 */
class PrinterRequest extends FormRequest
{
    /**
     * Authorized here rather than in the controller so an operator who may not write
     * never gets validation feedback on a form they cannot submit.
     */
    public function authorize(): bool
    {
        $printer = $this->route('printer');

        return $printer instanceof Printer
            ? Gate::allows('update', $printer)
            : Gate::allows('create', Printer::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // The Select's own two options, `receipt` and `badge`, taken off the enum the
            // column is cast to rather than retyped.
            'type' => ['required', Rule::in(array_column(PrintJobTypeEnum::cases(), 'value'))],
            'machine_id' => ['required', 'integer', 'exists:machines,id'],
            'default_paper_size' => ['nullable', 'string', 'max:255', Rule::in($this->paperSizeNames())],
            // Filament's Checkbox: not required, so an absent value is simply off.
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * `is_active` reaches the model as a real boolean even when the client leaves it out,
     * which is what an unchecked Filament Checkbox did.
     *
     * @return array<string, mixed>
     */
    public function validated($key = null, $default = null): mixed
    {
        $validated = parent::validated();
        $validated['is_active'] = (bool) ($validated['is_active'] ?? false);

        return $key === null ? $validated : data_get($validated, $key, $default);
    }

    /**
     * The paper sizes this printer has reported, which are the only valid values for
     * `default_paper_size`. Empty on create, so the field must be left blank there.
     *
     * @return array<int, string>
     */
    private function paperSizeNames(): array
    {
        $printer = $this->route('printer');

        if (! $printer instanceof Printer) {
            return [];
        }

        return collect($printer->paper_sizes ?? [])
            ->pluck('name')
            ->filter(fn ($name) => is_string($name) && $name !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Filament's labels, so a validation message names the field the way the form does.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'machine_id' => 'machine',
            'default_paper_size' => 'default paper size',
            'is_active' => 'is active',
        ];
    }
}
