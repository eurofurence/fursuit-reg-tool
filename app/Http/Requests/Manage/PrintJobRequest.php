<?php

namespace App\Http\Requests\Manage;

use App\Domain\Printing\Models\PrintBatch;
use App\Domain\Printing\Models\PrintJob;
use App\Enum\PrintJobStatusEnum;
use App\Enum\PrintJobTypeEnum;
use App\Http\Controllers\Manage\PrintJobController;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Create and update for /admin/print-jobs (audit 4.9, form schema).
 *
 * Authorisation happens here rather than in the controller body, because a FormRequest
 * validates before the action runs: gating in the controller would answer an unauthorised
 * write with a 422 about its payload instead of a 403.
 *
 * Three shapes differ from the Filament form, all of them plan decisions.
 *
 *  - `status` is a transition name rather than a value to write. payload() never carries
 *    it; the controller runs the model's own state handling instead (plan 2.10 #10,
 *    audit 22). The values accepted are the edges PrintJobStatusEnum allows from the
 *    record's current state, plus the state it is already in, which means "no change".
 *    A create fixes it at Pending: there is nothing to transition from, and a create page
 *    that could fabricate a Printed card would claim a card exists that nobody printed.
 *  - `print_batch_id` is required for a badge job. A batch-less badge job falls into the
 *    receipt-only unbatched lane, which `PrintJob::claimNextUnbatched()` filters to
 *    `type = Receipt`, so it sat Pending forever (audit 89).
 *  - `printable_type` and `printable_id` are collected on create. Both columns are NOT
 *    NULL and the Filament create form asked for neither, so creating a print job from
 *    admin has always thrown an integrity error. They are immutable afterwards: which
 *    thing a card is a print of is not an edit.
 */
class PrintJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        $printJob = $this->record();

        return $printJob instanceof PrintJob
            ? Gate::allows('update', $printJob)
            : Gate::allows('create', PrintJob::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $printJob = $this->record();

        $rules = [
            // Filament: Select ->relationship('printer', 'name') ->required().
            'printer_id' => ['required', 'integer', 'exists:printers,id'],
            'type' => ['required', 'string', Rule::in([
                PrintJobTypeEnum::Badge->value,
                PrintJobTypeEnum::Receipt->value,
            ])],
            'status' => ['required', 'string', Rule::in($this->statusValues())],
            // Filament: TextInput ->numeric() with a 0 default. The column is an
            // unsignedTinyInteger, so the bounds are the column's own.
            'priority' => ['nullable', 'integer', 'min:0', 'max:255'],
            'retry_count' => ['nullable', 'integer', 'min:0', 'max:255'],
            'error_message' => ['nullable', 'string'],
            'firmware_job_id' => ['nullable', 'string', 'max:64'],
            'firmware_job_uuid' => ['nullable', 'string', 'max:64'],
        ];

        if ($printJob instanceof PrintJob) {
            return $rules;
        }

        return $rules + [
            'print_batch_id' => [
                Rule::requiredIf(fn () => $this->input('type') === PrintJobTypeEnum::Badge->value),
                'nullable',
                'integer',
                Rule::exists('print_batches', 'id')
                    ->whereIn('status', PrintJobController::openBatchStatuses()),
            ],
            'printable_type' => ['required', 'string', Rule::in(array_keys(PrintJobController::PRINTABLES))],
            'printable_id' => ['required', 'integer', 'min:1', Rule::exists($this->printableTable(), 'id')],
        ];
    }

    /**
     * Filament's own labels for the fields that carry one, so a validation message names
     * them the way the form does.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'printer_id' => 'Printer',
            'print_batch_id' => 'Batch',
            'printable_type' => 'Printable Type',
            'printable_id' => 'Printable ID',
            'retry_count' => 'Retry Count',
            'error_message' => 'Error Message',
            'firmware_job_id' => 'Printer job id',
            'firmware_job_uuid' => 'Printer job UUID',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'print_batch_id.required' => 'A badge print job must belong to a batch, or nothing will ever print it.',
        ];
    }

    /**
     * The attributes the write applies directly. `status` is never among them, and
     * neither is anything the batch owns.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $validated = $this->validated();

        $payload = [
            'printer_id' => (int) $validated['printer_id'],
            'type' => PrintJobTypeEnum::from($validated['type']),
            // Filament defaulted both TextInputs to 0. An emptied field means the same
            // thing the default does rather than writing null into a ladder that has to
            // compare it.
            'priority' => (int) ($validated['priority'] ?? 0),
            'retry_count' => (int) ($validated['retry_count'] ?? 0),
            'error_message' => $validated['error_message'] ?? null,
            'firmware_job_id' => $validated['firmware_job_id'] ?? null,
            'firmware_job_uuid' => $validated['firmware_job_uuid'] ?? null,
        ];

        if ($this->record() instanceof PrintJob) {
            return $payload;
        }

        return $payload + [
            'printable_type' => $validated['printable_type'],
            'printable_id' => (int) $validated['printable_id'],
        ];
    }

    /**
     * The batch a new job joins, or null for an unbatched receipt.
     */
    public function batch(): ?PrintBatch
    {
        $id = $this->validated()['print_batch_id'] ?? null;

        return $id === null ? null : PrintBatch::find($id);
    }

    /**
     * The status to transition to, or null when it is unchanged.
     *
     * Re-submitting the state the record is already in is not a transition: the enum
     * would refuse it, and an unrelated edit that leaves the picker where it found it has
     * to save.
     */
    public function transitionTarget(): ?PrintJobStatusEnum
    {
        $printJob = $this->record();
        $target = $this->validated()['status'] ?? null;

        if (! $printJob instanceof PrintJob || $target === null) {
            return null;
        }

        if ($printJob->status?->value === $target) {
            return null;
        }

        return PrintJobStatusEnum::tryFrom($target);
    }

    /**
     * The bound record, or null on a create.
     */
    private function record(): ?PrintJob
    {
        $printJob = $this->route('print_job');

        return $printJob instanceof PrintJob ? $printJob : null;
    }

    /**
     * The values the status picker may carry, read from the same helper the picker is
     * built from so the two cannot disagree.
     *
     * @return array<int, string>
     */
    private function statusValues(): array
    {
        $printJob = $this->record();

        if (! $printJob instanceof PrintJob) {
            return [PrintJobStatusEnum::Pending->value];
        }

        return collect([$printJob->status, ...PrintJobController::allowedTransitions($printJob)])
            ->filter()
            ->map(fn (PrintJobStatusEnum $case) => $case->value)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The table `printable_id` has to exist in, given the type the form picked. An
     * unrecognised type falls back to the badges table; `printable_type` is validated
     * against the same list, so that branch is unreachable through the form.
     */
    private function printableTable(): string
    {
        $type = (string) $this->input('printable_type');

        return PrintJobController::PRINTABLES[$type]['table'] ?? 'badges';
    }
}
