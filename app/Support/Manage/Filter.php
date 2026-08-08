<?php

namespace App\Support\Manage;

use Closure;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Declares one filter of a manage table.
 *
 * Types:
 *  select   single or multiple choice; value is a string or array of strings
 *  ternary  three-state; value is '' (all), '1' or '0'
 *  boolean  checkbox; value is a bool, and `default` can make it on by default
 *  range    two numeric bounds; value is ['min' => string, 'max' => string]
 *  text     one free string; value is a string
 *  number   one free number, kept as a string so an empty bound stays distinguishable
 *  date     one ISO date; value is a string
 *
 * text, number and date exist because three modules were declaring an optionless
 * `select` and then rendering the control themselves on the page - the checkout list's
 * created_from / created_until, the print-job list's printable_id / printable_type. An
 * optionless select is a native dropdown with nothing in it, so the page had no choice.
 * Naming the shape here is what lets the filter bar render every declared filter and
 * keeps "add a filter" a declaration rather than a page rewrite.
 *
 * A filter with no `apply()` callback falls back to `where(key, value)`, or for a
 * boolean filter to nothing at all - a plain boolean filter must declare how it
 * narrows the query.
 *
 * `default` is what makes the two default-on filters in the audit survive the rewrite:
 * the fursuit status filter opens on `pending` and the machine archived ternary opens
 * blank, which is the only thing keeping archived machines out of the list.
 *
 * @implements Arrayable<string, mixed>
 */
final class Filter implements Arrayable
{
    /**
     * The token the client sends for a filter the operator has explicitly cleared.
     *
     * A `filter[...]` key that is simply absent means "not set", and that is what falls
     * back to `default`. Clearing needs a form of its own, or picking "All statuses" on
     * the fursuit list would send nothing and the list would snap straight back to
     * Pending. An empty string cannot be that form: ConvertEmptyStringsToNull runs
     * globally and turns `filter[status]=` into a missing key before this class sees it.
     * Mirrored by FILTER_CLEARED in resources/js/Components/Manage/useTableQuery.js.
     */
    public const CLEARED = '__none';

    /** @var array<string, string> */
    private array $options = [];

    private bool $multiple = false;

    private ?string $placeholder = null;

    private ?string $trueLabel = null;

    private ?string $falseLabel = null;

    private ?string $chipLabel = null;

    private bool $pinned = false;

    private mixed $default = null;

    private ?Closure $apply = null;

    private function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type,
    ) {}

    public static function select(string $key, ?string $label = null): self
    {
        return new self($key, $label ?? str($key)->headline()->toString(), 'select');
    }

    public static function ternary(string $key, ?string $label = null): self
    {
        return new self($key, $label ?? str($key)->headline()->toString(), 'ternary');
    }

    public static function boolean(string $key, ?string $label = null): self
    {
        return new self($key, $label ?? str($key)->headline()->toString(), 'boolean');
    }

    /**
     * Two numeric bounds, either of which may be left blank. The badge list's attendee
     * range is the one user of this.
     */
    public static function range(string $key, ?string $label = null): self
    {
        return new self($key, $label ?? str($key)->headline()->toString(), 'range');
    }

    public static function text(string $key, ?string $label = null): self
    {
        return new self($key, $label ?? str($key)->headline()->toString(), 'text');
    }

    public static function number(string $key, ?string $label = null): self
    {
        return new self($key, $label ?? str($key)->headline()->toString(), 'number');
    }

    public static function date(string $key, ?string $label = null): self
    {
        return new self($key, $label ?? str($key)->headline()->toString(), 'date');
    }

    /**
     * @param  array<string, string>  $options  value => label
     */
    public function options(array $options): self
    {
        $this->options = $options;

        return $this;
    }

    public function multiple(bool $multiple = true): self
    {
        $this->multiple = $multiple;

        return $this;
    }

    public function placeholder(string $placeholder): self
    {
        $this->placeholder = $placeholder;

        return $this;
    }

    public function trueLabel(string $label): self
    {
        $this->trueLabel = $label;

        return $this;
    }

    public function falseLabel(string $label): self
    {
        $this->falseLabel = $label;

        return $this;
    }

    /**
     * The short form the chip wears once the filter is applied, when the full label is
     * too long for a pill that already carries its value. `Attendee id range` reads
     * `Attendee 1-600` with a chip label of `Attendee`; unset, the chip uses `label` and
     * nothing changes for the modules that never set it.
     */
    public function chipLabel(string $label): self
    {
        $this->chipLabel = $label;

        return $this;
    }

    /**
     * Whether the filter is on the bar from the start rather than waiting behind the
     * `Filter` button.
     *
     * Default false, which is the Shopify model the panel now follows: nothing is on the
     * bar until it is either chosen or carrying a value. A filter with a declared
     * `default` is already carrying one on first load, so it appears without needing
     * this. Pinning is for a filter an operator is expected to reach for on every visit
     * to that list, and a pinned chip is cleared rather than removed.
     */
    public function pinned(bool $pinned = true): self
    {
        $this->pinned = $pinned;

        return $this;
    }

    public function default(mixed $default): self
    {
        $this->default = $default;

        return $this;
    }

    /**
     * @param  Closure(Builder, mixed): void  $callback
     */
    public function apply(Closure $callback): self
    {
        $this->apply = $callback;

        return $this;
    }

    public function defaultValue(): mixed
    {
        return $this->default ?? $this->emptyValue();
    }

    /**
     * The blank value for this filter's type, ignoring any declared default. This is
     * what an explicit clear resolves to, which is the whole point of it being separate
     * from defaultValue().
     */
    public function emptyValue(): mixed
    {
        return match ($this->type) {
            'boolean' => false,
            'select' => $this->multiple ? [] : '',
            'range' => ['min' => '', 'max' => ''],
            default => '',
        };
    }

    /**
     * Whether the given request value should narrow the query at all.
     */
    public function isActive(mixed $value): bool
    {
        return match ($this->type) {
            'boolean' => (bool) $value,
            'select' => $this->multiple ? ! empty($value) : $value !== '' && $value !== null,
            'ternary' => $value === '1' || $value === '0' || $value === true || $value === false,
            'range' => ($value['min'] ?? '') !== '' || ($value['max'] ?? '') !== '',
            default => $value !== '' && $value !== null,
        };
    }

    /**
     * Coerce a raw request value into the shape the filter works with.
     */
    public function normalize(mixed $value): mixed
    {
        return match ($this->type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'ternary' => match (true) {
                $value === true, $value === '1', $value === 1 => '1',
                $value === false, $value === '0', $value === 0 => '0',
                default => '',
            },
            'select' => $this->multiple ? array_values(array_filter((array) $value, fn ($v) => $v !== '' && $v !== null)) : (string) $value,
            // Guarded, unlike the select above: these three take whatever the operator
            // typed, and `filter[printable_type][]=x` would otherwise be an array cast
            // to string. A non-scalar is not a value anyone can have meant, so it is the
            // same as not setting the filter.
            'text', 'number', 'date' => is_scalar($value) ? (string) $value : '',
            'range' => [
                'min' => is_numeric($value['min'] ?? null) ? (string) $value['min'] : '',
                'max' => is_numeric($value['max'] ?? null) ? (string) $value['max'] : '',
            ],
            default => $value,
        };
    }

    public function applyTo(Builder $query, mixed $value): void
    {
        if ($this->apply) {
            ($this->apply)($query, $value);

            return;
        }

        match ($this->type) {
            'select' => $this->multiple
                ? $query->whereIn($this->key, $value)
                : $query->where($this->key, $value),
            'ternary' => $query->where($this->key, $value === '1'),
            'range' => $this->applyRange($query, $value),
            'text', 'number', 'date' => $query->where($this->key, $value),
            default => null,
        };
    }

    /**
     * @param  array{min: string, max: string}  $value
     */
    private function applyRange(Builder $query, array $value): void
    {
        if ($value['min'] !== '') {
            $query->where($this->key, '>=', $value['min']);
        }

        if ($value['max'] !== '') {
            $query->where($this->key, '<=', $value['max']);
        }
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
            'options' => $this->options === [] ? null : collect($this->options)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values()
                ->all(),
            'multiple' => $this->multiple,
            'placeholder' => $this->placeholder,
            'trueLabel' => $this->trueLabel,
            'falseLabel' => $this->falseLabel,
            'chipLabel' => $this->chipLabel ?? $this->label,
            'pinned' => $this->pinned,
            // The client needs the declared default as well as the resolved value: it is
            // the only way to tell a filter that must be removed with Filter::CLEARED
            // from one that is removed by dropping the key. See useTableQuery.
            'default' => $this->defaultValue(),
        ];
    }
}
