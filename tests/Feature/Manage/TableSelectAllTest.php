<?php

/*
 * Selecting past the page.
 *
 * A bulk action posts `ids[]`, and a checkbox can only tick a row that is rendered, so
 * every bulk action in the panel used to stop at one page: 100 badges per print run, no
 * matter what the filter had just isolated. `X-Table-Select-All` is the way past it - the
 * list answers with the key of every record the current query matches, and the client
 * hands those keys to the same bulk action it always did.
 *
 * The two things that must stay true are asserted here rather than in any one module,
 * because both belong to App\Support\Manage\Table and every list inherits them: the keys
 * are the *filtered* set and not the table, and asking for them does not disturb the page
 * that was going to be rendered anyway.
 */

use App\Models\Fursuit\Fursuit;
use App\Models\Fursuit\States\Approved;
use App\Models\Fursuit\States\Pending;
use App\Support\Manage\Column;
use App\Support\Manage\Filter;
use App\Support\Manage\Table;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;

beforeEach(function () {
    $this->pending = Fursuit::factory()->count(4)->create(['status' => Pending::$name]);
    $this->approved = Fursuit::factory()->count(7)->create(['status' => Approved::$name]);

    $this->envelope = function (array $query = [], bool $selectAll = false) {
        $request = Request::create('/admin/fursuits', 'GET', $query);

        if ($selectAll) {
            $request->headers->set(Table::SELECT_ALL_HEADER, '1');
        }

        return Table::make(Fursuit::query())
            ->name('fursuits')
            ->columns([Column::text('name', 'Name')])
            ->filters([
                Filter::select('status', 'Status')
                    ->options([Pending::$name => 'Pending', Approved::$name => 'Approved'])
                    ->apply(fn ($builder, $value) => $builder->where('status', $value)),
            ])
            ->perPage(5)
            ->defaultSort('id')
            ->rows(fn (Fursuit $fursuit) => ['name' => $fursuit->name])
            ->toArray($request);
    };
});

test('a list that was not asked carries no ids', function () {
    // Null rather than an empty array: eleven rows of keys on every poll of every list,
    // for a button nobody pressed, is the cost this header exists to avoid.
    expect(($this->envelope)()['meta']['allIds'])->toBeNull();
});

test('the header answers with every matching key, not the page', function () {
    $envelope = ($this->envelope)([], selectAll: true);

    expect($envelope['rows'])->toHaveCount(5)
        ->and($envelope['meta']['total'])->toBe(11)
        ->and($envelope['meta']['allIds'])->toHaveCount(11)
        ->and($envelope['meta']['allIds'])->each->toBeInt();

    // The point of the whole feature: keys the operator could not have ticked, because
    // the rows carrying them were never rendered.
    $onPage = collect($envelope['rows'])->pluck('id');

    expect(collect($envelope['meta']['allIds'])->diff($onPage))->toHaveCount(6);
});

test('the keys are the filtered set, not the table', function () {
    $envelope = ($this->envelope)(['filter' => ['status' => Approved::$name]], selectAll: true);

    expect($envelope['meta']['total'])->toBe(7)
        ->and($envelope['meta']['allIds'])->toHaveCount(7)
        ->and(collect($envelope['meta']['allIds'])->sort()->values()->all())
        ->toBe($this->approved->pluck('id')->sort()->values()->all());
});

test('asking for the keys leaves the page it came with alone', function () {
    // The ids are plucked off a clone taken before paginate(), which is the order that
    // matters: paginate() puts its limit and offset on the builder itself, so a pluck
    // taken afterwards would return one page of keys, and a clone taken afterwards would
    // hand the paginator a builder that had already been read.
    // The page number is read off the global request by the paginator, not off the one
    // built here, so it is set where the paginator actually looks.
    Paginator::currentPageResolver(fn () => 2);

    $plain = ($this->envelope)();
    $asked = ($this->envelope)([], selectAll: true);

    expect($asked['rows'])->toEqual($plain['rows'])
        ->and($asked['meta']['page'])->toBe(2)
        ->and($asked['meta']['from'])->toBe(6)
        ->and($asked['meta']['to'])->toBe(10);
});
