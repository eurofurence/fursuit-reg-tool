<?php

namespace App\Http\Controllers\Manage;

use App\Domain\CatchEmAll\Models\SpecialCode;
use App\Domain\CatchEmAll\SpecialActions\SpecialCodeActionRegistry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\SpecialCodeRequest;
use App\Models\Event;
use App\Support\Manage\Action;
use App\Support\Manage\Column;
use App\Support\Manage\EventScope;
use App\Support\Manage\Table;
use App\Support\Manage\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Response;

/**
 * Catch-Em-All special codes (successor to SpecialCodeResource and ManageSpecialCodes).
 *
 * Two of the four columns 500 the whole list today, both for the same reason: their
 * formatting closures type-hint `string $state` for values that are routinely absent
 * (audit landmines 30 and 31). Nothing is formatted in a closure here; the row
 * transformer hands the table plain nullable strings and the client renders its own
 * fallback, which is the single fix plan 2.10 #7 asks for across four such columns.
 *
 * Create and edit are real pages with real URLs rather than the Filament modals, and
 * the list is event-scoped, which it never was even though every row carries an
 * `event_id` (plan 2.9).
 */
class SpecialCodeController extends Controller
{
    /**
     * Filament's model label for this resource, as its delete modals render it.
     */
    private const MODEL_LABEL = 'special code';

    private const PLURAL_LABEL = 'special codes';

    /**
     * The list envelope is spread across top-level props rather than nested under one,
     * because useTableQuery reloads `rows`, `meta`, `filters`, `sort` and `search` as a
     * partial visit and Inertia filters partials by top-level key. Nested under a `table`
     * prop those five keys resolve to null, the client merges the nulls over the props it
     * already has, and every sort, page and per-page click is a silent no-op.
     */
    public function index(Request $request, EventScope $scope): Response
    {
        Gate::authorize('viewAny', SpecialCode::class);

        return inertia('Manage/SpecialCodes/Index', $this->table($request, $scope));
    }

    public function create(): Response
    {
        Gate::authorize('create', SpecialCode::class);

        return inertia('Manage/SpecialCodes/Form', $this->formProps(null));
    }

    public function store(SpecialCodeRequest $request): RedirectResponse
    {
        SpecialCode::create($request->payload());

        // Filament's stock create toast; this resource declares none of its own.
        Toast::flashSuccess('Created');

        return redirect()->to(Table::returnUrl('special-codes', route('admin.special-codes.index')));
    }

    public function edit(SpecialCode $code): Response
    {
        Gate::authorize('update', $code);

        return inertia('Manage/SpecialCodes/Form', $this->formProps($code));
    }

    public function update(SpecialCodeRequest $request, SpecialCode $code): RedirectResponse
    {
        $code->update($request->payload());

        Toast::flashSuccess('Saved');

        return redirect()->to(Table::returnUrl('special-codes', route('admin.special-codes.index')));
    }

    /**
     * Hard delete, as today. The model has no soft deletes.
     */
    public function destroy(SpecialCode $code): RedirectResponse
    {
        Gate::authorize('delete', $code);

        $code->delete();

        Toast::flashSuccess('Deleted');

        return back();
    }

    /**
     * All-or-nothing, per plan 2.5: if any selected record fails the policy nothing is
     * deleted and a danger toast says so, rather than half the selection vanishing.
     *
     * The endpoint authorizes the same question the bulk button is offered on, as Users
     * does. Without it the per-record loop is the only guard, and a caller who fails the
     * policy but sends ids matching no rows walks the empty loop and gets the success
     * toast rather than a 403.
     */
    public function bulkDestroy(Request $request): RedirectResponse
    {
        Gate::authorize('delete', new SpecialCode);

        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        $codes = SpecialCode::whereIn('id', $validated['ids'])->get();

        foreach ($codes as $code) {
            if (Gate::denies('delete', $code)) {
                Toast::flashDanger('Nothing was deleted', 'You may not delete every selected special code.');

                return back();
            }
        }

        SpecialCode::whereIn('id', $codes->modelKeys())->delete();

        Toast::flashSuccess('Deleted');

        return back();
    }

    /**
     * The label for a stored class name: the option label when it is one of ours, the
     * raw class otherwise, and null when there is none.
     *
     * The Filament closure was `fn (string $state): string`, so a code saved without a
     * class (the field is not required) took the entire table down with a TypeError
     * (audit 30). The null is handled here and the client renders the empty-cell
     * placeholder.
     */
    public static function classLabel(?string $className): ?string
    {
        return SpecialCodeActionRegistry::labelFor($className);
    }

    /**
     * `constructor_data` as text. The attribute is cast to `object`, so the raw column
     * value is re-encoded rather than printed through a string cast that would render
     * an object as the literal "Object".
     */
    public static function dataPreview(mixed $data): ?string
    {
        if ($data === null) {
            return null;
        }

        if (is_string($data)) {
            return $data === '' ? null : $data;
        }

        $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false || $encoded === 'null' ? null : $encoded;
    }

    /**
     * The Catch-Em-All auto-catch link for a code. Verbatim from
     * SpecialCodeResource::buildCatchAutoUrl().
     */
    public static function catchUrl(string $code): string
    {
        return self::catchUrlBase().'?code='.urlencode($code).'&auto';
    }

    /**
     * The unchanging half of the link, so the form's preview can update as the operator
     * types instead of being computed once at render (audit 33).
     */
    public static function catchUrlBase(): string
    {
        $scheme = parse_url(config('app.url'), PHP_URL_SCHEME) ?: 'https';
        $baseDomain = (string) config('fcea.domain', 'catch.localhost');

        return sprintf('%s://%s/', $scheme, $baseDomain);
    }

    /**
     * @return array<string, mixed>
     */
    private function table(Request $request, EventScope $scope): array
    {
        // `with('event')` is the whole of the N+1 fix: the Event column ran one
        // `Event::where('id', $state)` per row (audit 99).
        $query = $scope->apply(SpecialCode::query()->with('event'));

        return Table::make($query)
            ->name('special-codes')
            ->columns([
                Column::text('code', 'Code')->sortable(),
                Column::text('class_name', 'Class')->sortable(),
                Column::text('constructor_data', 'Data')->sortable(),
                Column::text('event_id', 'Event')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([])
            ->rows(fn (SpecialCode $specialCode) => [
                'code' => $specialCode->code,
                'class_name' => self::classLabel($specialCode->class_name),
                'constructor_data' => self::dataPreview($specialCode->constructor_data),
                // Null when the event row is gone. Events are hard-deleted, so this
                // is a state the list has to survive rather than one it can rule out.
                'event_id' => $specialCode->event?->name,
            ])
            ->recordUrl(fn (SpecialCode $specialCode) => Gate::allows('update', $specialCode)
                ? route('admin.special-codes.edit', $specialCode)
                : null)
            ->rowActions(fn (SpecialCode $specialCode) => array_values(array_filter([
                Gate::allows('update', $specialCode)
                    ? Action::link('edit', 'Edit', route('admin.special-codes.edit', $specialCode))->icon('pencil')
                    : null,
                Gate::allows('delete', $specialCode)
                    ? Action::delete('delete', 'Delete', route('admin.special-codes.destroy', $specialCode))
                        ->icon('trash-2')
                        ->tone('danger')
                        ->confirmDelete(self::MODEL_LABEL)
                    : null,
            ])))
            ->bulkActions(array_values(array_filter([
                // The button offers a delete, so it asks the delete question. A bare
                // class name would reach SpecialCodePolicy::delete() as its $model
                // argument and fail the type hint, hence the throwaway instance: the
                // policy answers on the actor, not on the row.
                Gate::allows('delete', new SpecialCode)
                    ? Action::delete('bulk-delete', 'Delete selected', route('admin.special-codes.bulk.destroy'))
                        ->icon('trash-2')
                        ->tone('danger')
                        ->confirm(
                            'Delete selected '.self::PLURAL_LABEL,
                            Action::DEFAULT_CONFIRM_DESCRIPTION,
                            'Delete',
                        )
                    : null,
            ])))
            ->pageActions(array_values(array_filter([
                Gate::allows('create', SpecialCode::class)
                    ? Action::link('create', 'New special code', route('admin.special-codes.create'))->icon('plus')
                    : null,
            ])))
            ->toArray($request);
    }

    /**
     * Shared by create and edit: one page component, one set of fields.
     *
     * `constructor_data` no longer ships as a JSON string to be typed into. The record
     * carries `data`, the declared keys of the selected class with their stored values,
     * and `actionSchemas` carries the declaration for every class, so the client can swap
     * the fields when the Select changes without another request. The stored document is
     * still shipped, as `storedData`, but read-only: it is the only way an operator can
     * see a key the current schema does not declare.
     *
     * @return array<string, mixed>
     */
    private function formProps(?SpecialCode $specialCode): array
    {
        $className = $specialCode?->class_name ?? '';
        $residue = $specialCode
            ? SpecialCodeActionRegistry::residue($className, $specialCode->constructor_data)
            : null;

        return [
            'specialCode' => $specialCode ? [
                'id' => $specialCode->id,
                'event_id' => $specialCode->event_id,
                'class_name' => $specialCode->class_name,
                'data' => SpecialCodeActionRegistry::declaredValues($className, $specialCode->constructor_data),
                'storedData' => self::dataPreview($specialCode->constructor_data),
                // Non-null exactly when the stored document holds something the fields do
                // not cover, which is what the form warns about.
                'unmanagedData' => self::dataPreview($residue),
                'code' => $specialCode->code,
            ] : null,
            'events' => Event::orderByDesc('starts_at')
                ->get()
                ->map(fn (Event $event) => ['value' => $event->id, 'label' => $event->name])
                ->all(),
            'classOptions' => $this->classOptions($specialCode),
            'actionSchemas' => SpecialCodeActionRegistry::schemas(),
            'catchUrlBase' => self::catchUrlBase(),
        ];
    }

    /**
     * The Select options, plus the record's own class when that class is no longer one of
     * them.
     *
     * A row can name a class that has since been removed, and the Select would otherwise
     * silently show the first option instead, so an operator opening the page to change
     * the code would rewire the action without noticing. The stored class is shown,
     * labelled unavailable, and `Rule::in` still refuses it on save: the operator has to
     * pick a class the redeem path can instantiate before the record can be written.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function classOptions(?SpecialCode $specialCode): array
    {
        $options = collect(SpecialCodeActionRegistry::options())
            ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
            ->values()
            ->all();

        $className = $specialCode?->class_name;

        if ($className !== null && $className !== '' && ! SpecialCodeActionRegistry::has($className)) {
            $options[] = ['value' => $className, 'label' => $className.' (unavailable)'];
        }

        return $options;
    }
}
