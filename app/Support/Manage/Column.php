<?php

namespace App\Support\Manage;

use Closure;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Declares one column of a manage table.
 *
 * The `type` decides how the client renders the cell value produced by the table's
 * row transformer:
 *
 *  text      string|null, or ['display' => string, 'title' => ?string] when the cell is
 *            truncated and the full value belongs in a tooltip
 *  number    int|float, or ['display' => string, 'description' => ?string]
 *  money     integer cents, or ['value' => int cents, 'description' => ?string].
 *            Never a preformatted string: see below
 *  badge     Status::make() triple
 *  image     url string|null. `circular()` makes the thumbnail a round avatar rather
 *            than the default rounded rectangle
 *  bool      boolean
 *  datetime  string, or ['display' => string, 'title' => ?string, 'description' => ?string]
 *  duration  preformatted string
 *  color     hex string
 *  copyable  string
 *  toggle    ['value' => bool, 'url' => string]  (writes immediately)
 *  icon      ['icon' => string, 'tone' => string, 'title' => ?string]
 *
 * Money is the one type that is not passed through untouched. Every money column in this
 * database is an integer number of cents and the conversion used to be done by hand at each
 * render site: five of the six `->money(` calls in the old panel divided by 100 and the
 * badge Total column did not, so badge totals rendered a hundred times too high while the
 * checkout view showed raw cents next to a divided column.
 * There is therefore no undivided variant here. A money column takes cents and Table hands
 * the client euros; the call site is not offered a choice it could get wrong.
 *
 * @implements Arrayable<string, mixed>
 */
final class Column implements Arrayable
{
    private bool $sortable = false;

    private ?string $sortKey = null;

    private ?Closure $sortUsing = null;

    private bool $searchable = false;

    private ?string $searchKey = null;

    private bool $toggleable = false;

    private bool $hiddenByDefault = false;

    private string $align = 'left';

    private ?string $fallback = null;

    private ?string $width = null;

    private bool $circular = false;

    private function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type,
    ) {}

    public static function make(string $key, ?string $label = null, string $type = 'text'): self
    {
        return new self($key, $label ?? str($key)->headline()->toString(), $type);
    }

    public static function text(string $key, ?string $label = null): self
    {
        return self::make($key, $label, 'text');
    }

    public static function number(string $key, ?string $label = null): self
    {
        return self::make($key, $label, 'number')->align('right');
    }

    /**
     * A column of integer cents, rendered as euros. There is deliberately no variant that
     * skips the division.
     */
    public static function money(string $key, ?string $label = null): self
    {
        return self::make($key, $label, 'money')->align('right');
    }

    public static function badge(string $key, ?string $label = null): self
    {
        return self::make($key, $label, 'badge');
    }

    public static function image(string $key, ?string $label = null): self
    {
        return self::make($key, $label, 'image');
    }

    /**
     * the old panel's `ImageColumn->circular()`. The two image columns in the panel - the
     * fursuit list's own image and the badge list's `fursuit.image` - are both circular
     * avatars there, and both read as one when the thumbnail is a head shot.
     *
     * A shape flag rather than a free geometry: `circular()` is the only ImageColumn
     * modifier either column uses, and one flag keeps every image cell in the panel to
     * two known shapes instead of an open set of sizes.
     */
    public function circular(bool $circular = true): self
    {
        $this->circular = $circular;

        return $this;
    }

    public static function bool(string $key, ?string $label = null): self
    {
        return self::make($key, $label, 'bool')->align('center');
    }

    public static function datetime(string $key, ?string $label = null): self
    {
        return self::make($key, $label, 'datetime');
    }

    public static function duration(string $key, ?string $label = null): self
    {
        return self::make($key, $label, 'duration')->align('right');
    }

    public static function color(string $key, ?string $label = null): self
    {
        return self::make($key, $label, 'color');
    }

    public static function copyable(string $key, ?string $label = null): self
    {
        return self::make($key, $label, 'copyable');
    }

    public static function toggle(string $key, ?string $label = null): self
    {
        return self::make($key, $label, 'toggle')->align('center');
    }

    public static function icon(string $key, ?string $label = null): self
    {
        return self::make($key, $label, 'icon')->align('center');
    }

    /**
     * @param  string|null  $sortKey  Database column to sort by, when it differs from the cell key.
     */
    public function sortable(?string $sortKey = null): self
    {
        $this->sortable = true;
        $this->sortKey = $sortKey;

        return $this;
    }

    /**
     * Custom sort, required for columns that sort across a relation.
     *
     * @param  Closure(Builder, string): void  $callback
     */
    public function sortUsing(Closure $callback): self
    {
        $this->sortable = true;
        $this->sortUsing = $callback;

        return $this;
    }

    /**
     * @param  string|null  $searchKey  Column, or `relation.column` to search through a relation.
     */
    public function searchable(?string $searchKey = null): self
    {
        $this->searchable = true;
        $this->searchKey = $searchKey;

        return $this;
    }

    public function toggleable(bool $hiddenByDefault = false): self
    {
        $this->toggleable = true;
        $this->hiddenByDefault = $hiddenByDefault;

        return $this;
    }

    public function align(string $align): self
    {
        $this->align = $align;

        return $this;
    }

    /**
     * Rendered instead of an empty cell.
     */
    public function fallback(string $fallback): self
    {
        $this->fallback = $fallback;

        return $this;
    }

    public function width(string $width): self
    {
        $this->width = $width;

        return $this;
    }

    public function isSortable(): bool
    {
        return $this->sortable;
    }

    public function isSearchable(): bool
    {
        return $this->searchable;
    }

    public function isHiddenByDefault(): bool
    {
        return $this->hiddenByDefault;
    }

    public function resolvedSortKey(): ?string
    {
        return $this->sortKey ?? $this->key;
    }

    public function resolvedSearchKey(): string
    {
        return $this->searchKey ?? $this->key;
    }

    public function sortCallback(): ?Closure
    {
        return $this->sortUsing;
    }

    /**
     * Last pass over a cell value before it leaves the server. Every type but money is
     * already in its final shape, so this exists for the one conversion that must not be
     * left to the call site.
     */
    public function formatCell(mixed $value): mixed
    {
        if ($this->type !== 'money') {
            return $value;
        }

        if (is_array($value)) {
            return [
                'display' => self::euros($value['value'] ?? null),
                'description' => $value['description'] ?? null,
            ];
        }

        return self::euros($value);
    }

    /**
     * The one money formatter. Matches DbService::formatEuro(), which is the only place in
     * the app that already got this right in every branch.
     */
    public static function euros(mixed $cents): ?string
    {
        if (! is_numeric($cents)) {
            return null;
        }

        return '€'.number_format(((int) $cents) / 100, 2);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type,
            'align' => $this->align,
            'sortable' => $this->sortable,
            'sortKey' => $this->sortUsing ? $this->key : $this->resolvedSortKey(),
            'toggleable' => $this->toggleable,
            'hiddenByDefault' => $this->hiddenByDefault,
            'fallback' => $this->fallback,
            'width' => $this->width,
            'circular' => $this->circular,
        ];
    }
}
