<?php

/*
 * The filter contract every list page inherits from App\Support\Manage\Table (plan 2.3).
 *
 * The case that matters is the third state. A `filter[...]` key that is absent means
 * "not set" and falls back to the declared default, which is how the fursuit list keeps
 * opening on Pending. Clearing a filter therefore cannot be expressed by dropping the
 * key, and it cannot be expressed by an empty value either: ConvertEmptyStringsToNull
 * runs globally and turns `filter[status]=` back into a missing key. Without a token of
 * its own, picking "All statuses" silently snaps the list back to Pending and there is
 * no URL that says otherwise.
 */

use App\Models\Fursuit\Fursuit;
use App\Models\Fursuit\States\Approved;
use App\Models\Fursuit\States\Pending;
use App\Models\Fursuit\States\Rejected;
use App\Support\Manage\Column;
use App\Support\Manage\Filter;
use App\Support\Manage\Table;
use Illuminate\Http\Request;

beforeEach(function () {
    Fursuit::factory()->count(2)->create(['status' => Pending::$name]);
    Fursuit::factory()->count(3)->create(['status' => Approved::$name]);
    Fursuit::factory()->create(['status' => Rejected::$name]);

    $this->envelope = function (array $query, bool $multiple = false) {
        $filter = Filter::select('status', 'Status')
            ->options([Pending::$name => 'Pending', Approved::$name => 'Approved', Rejected::$name => 'Rejected'])
            ->multiple($multiple)
            ->default($multiple ? [Pending::$name] : Pending::$name);

        return Table::make(Fursuit::query())
            ->name('fursuits')
            ->columns([Column::text('name', 'Name')])
            ->filters([$filter])
            ->rows(fn (Fursuit $fursuit) => ['name' => $fursuit->name])
            ->toArray(Request::create('/manage/fursuits', 'GET', $query));
    };
});

test('a filter absent from the request falls back to its declared default', function () {
    $envelope = ($this->envelope)([]);

    expect($envelope['meta']['total'])->toBe(2)
        ->and($envelope['filters'][0]['value'])->toBe(Pending::$name);
});

test('an explicit value narrows to that value', function () {
    $envelope = ($this->envelope)(['filter' => ['status' => Approved::$name]]);

    expect($envelope['meta']['total'])->toBe(3)
        ->and($envelope['filters'][0]['value'])->toBe(Approved::$name);
});

test('clearing a defaulted filter shows everything instead of snapping back to the default', function () {
    $envelope = ($this->envelope)(['filter' => ['status' => Filter::CLEARED]]);

    expect($envelope['meta']['total'])->toBe(6)
        ->and($envelope['filters'][0]['value'])->toBe('');
});

test('an empty string is not a clear, because the framework never lets one through', function () {
    // ConvertEmptyStringsToNull is what actually reaches a controller, so the request
    // this table sees for `filter[status]=` is the one with no key at all. Asserting the
    // fallback here is asserting that nobody re-adds '' as the clear token.
    expect(($this->envelope)(['filter' => ['status' => null]])['meta']['total'])->toBe(2);
});

test('clearing a multi-select filter clears it rather than re-defaulting it', function () {
    // The Clear button sends the same token, not an empty indexed array.
    $cleared = ($this->envelope)(['filter' => ['status' => Filter::CLEARED]], multiple: true);

    expect($cleared['meta']['total'])->toBe(6)
        ->and($cleared['filters'][0]['value'])->toBe([]);

    $defaulted = ($this->envelope)([], multiple: true);

    expect($defaulted['meta']['total'])->toBe(2);
});

test('emptyValue and defaultValue are different questions', function () {
    $filter = Filter::select('status')->default(Pending::$name);

    expect($filter->defaultValue())->toBe(Pending::$name)
        ->and($filter->emptyValue())->toBe('');

    $ternary = Filter::ternary('archived')->default('0');

    expect($ternary->defaultValue())->toBe('0')
        ->and($ternary->emptyValue())->toBe('');
});
