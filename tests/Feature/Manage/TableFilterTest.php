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
            ->toArray(Request::create('/admin/fursuits', 'GET', $query));
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

/*
 * The three free-value types. They exist because the checkout, badge and print-job lists
 * were each declaring an optionless select and then drawing the control themselves; the
 * filter bar renders a declared type, so the shape has to be declarable.
 */

test('text, number and date filters narrow the query with no apply callback', function () {
    Fursuit::factory()->create(['name' => 'Solo']);

    $envelope = fn (array $query) => Table::make(Fursuit::query())
        ->name('fursuits')
        ->columns([Column::text('name', 'Name')])
        ->filters([Filter::text('name', 'Name')])
        ->rows(fn (Fursuit $fursuit) => ['name' => $fursuit->name])
        ->toArray(Request::create('/admin/fursuits', 'GET', $query));

    // Seven: the six the suite seeds, plus Solo.
    expect($envelope([])['meta']['total'])->toBe(7)
        ->and($envelope(['filter' => ['name' => 'Solo']])['meta']['total'])->toBe(1)
        ->and($envelope(['filter' => ['name' => Filter::CLEARED]])['meta']['total'])->toBe(7);

    // `filter[name][]=Solo` is not a value anyone can have meant, and a free-value filter
    // must not hand an array to a string comparison.
    expect($envelope(['filter' => ['name' => ['Solo']]])['meta']['total'])->toBe(7);
});

test('a date filter is a string value, applied and cleared like any other', function () {
    $filter = Filter::date('created_from', 'Created From');

    expect($filter->emptyValue())->toBe('')
        ->and($filter->isActive('2026-08-01'))->toBeTrue()
        ->and($filter->isActive(''))->toBeFalse()
        ->and($filter->normalize('2026-08-01'))->toBe('2026-08-01');
});

test('a range is active on either bound alone and cleared by the same token', function () {
    $filter = Filter::range('attendee_id_range');

    expect($filter->isActive(['min' => '5', 'max' => '']))->toBeTrue()
        ->and($filter->isActive(['min' => '', 'max' => '9']))->toBeTrue()
        ->and($filter->isActive(['min' => '', 'max' => '']))->toBeFalse()
        ->and($filter->emptyValue())->toBe(['min' => '', 'max' => '']);

    $envelope = fn (array $query) => Table::make(Fursuit::query())
        ->name('fursuits')
        ->columns([Column::text('name', 'Name')])
        ->filters([Filter::range('id')->default(['min' => '1', 'max' => '1'])])
        ->rows(fn (Fursuit $fursuit) => ['name' => $fursuit->name])
        ->toArray(Request::create('/admin/fursuits', 'GET', $query));

    // Half a range is still a range, and the cleared token still beats the default: the
    // chip's Remove has to reach every type the same way.
    expect($envelope([])['meta']['total'])->toBe(1)
        ->and($envelope(['filter' => ['id' => ['min' => '2']]])['meta']['total'])->toBe(5)
        ->and($envelope(['filter' => ['id' => Filter::CLEARED]])['meta']['total'])->toBe(6);
});

/*
 * The two attributes the chip UI added. Both default, so the sixteen modules that never
 * mention them keep the envelope they had plus two predictable keys.
 */

test('chipLabel falls back to the label and pinned is off unless declared', function () {
    $plain = Filter::select('status', 'Status')->toArray();

    expect($plain['chipLabel'])->toBe('Status')
        ->and($plain['pinned'])->toBeFalse();

    $short = Filter::range('attendee_id_range', 'Attendee id range')
        ->chipLabel('Attendee')
        ->pinned()
        ->toArray();

    // The full label is what the menu and the screen reader get; the chip is the only
    // place the short form is used, so the label itself must not move.
    expect($short['label'])->toBe('Attendee id range')
        ->and($short['chipLabel'])->toBe('Attendee')
        ->and($short['pinned'])->toBeTrue();
});

test('emptyValue and defaultValue are different questions', function () {
    $filter = Filter::select('status')->default(Pending::$name);

    expect($filter->defaultValue())->toBe(Pending::$name)
        ->and($filter->emptyValue())->toBe('');

    $ternary = Filter::ternary('archived')->default('0');

    expect($ternary->defaultValue())->toBe('0')
        ->and($ternary->emptyValue())->toBe('');
});
