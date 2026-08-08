<?php

namespace App\Domain\CatchEmAll\SpecialActions;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Validation\Rule;

/**
 * One key of an action class's `constructor_data`, declared by the class that reads it.
 *
 * The panel does not offer a JSON textarea any more: the operator fills in fields and the
 * server assembles the object. That only works if something says which keys an action takes
 * and what they may hold, so each action class declares its own list of these
 * (see ConfigurableSpecialCodeAction) and the class stays the single source of truth. The
 * request builds its rules from the list, the form props ship the same list to the client,
 * and the payload is cast back through it before it is stored.
 *
 * `type` is the semantic type, which decides the validation rules and the cast. `control`
 * is what FormField.vue renders for it, which is a smaller set: two numeric types share one
 * number input.
 *
 * @implements Arrayable<string, mixed>
 */
final class ActionField implements Arrayable
{
    public const TYPE_TEXT = 'text';

    public const TYPE_TEXTAREA = 'textarea';

    public const TYPE_INTEGER = 'integer';

    public const TYPE_DECIMAL = 'decimal';

    public const TYPE_TOGGLE = 'toggle';

    public const TYPE_SELECT = 'select';

    private bool $required = false;

    private mixed $default = null;

    private ?string $help = null;

    /** @var array<int, mixed> */
    private array $rules = [];

    /** @var array<int, array{value: string, label: string}> */
    private array $options = [];

    private function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $type,
    ) {
        /*
         * The name becomes both a JSON object key and the `data.<name>` path a validation
         * error is reported on, so a name carrying a dot or a star would split into a path
         * the form can never match and the error would land nowhere. Refused at
         * declaration time rather than debugged at request time.
         */
        if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
            throw new \InvalidArgumentException(
                "Action field name '{$name}' must be snake_case: a lowercase letter followed by letters, digits or underscores."
            );
        }
    }

    public static function text(string $name, string $label): self
    {
        return new self($name, $label, self::TYPE_TEXT);
    }

    public static function textarea(string $name, string $label): self
    {
        return new self($name, $label, self::TYPE_TEXTAREA);
    }

    public static function integer(string $name, string $label): self
    {
        return new self($name, $label, self::TYPE_INTEGER);
    }

    public static function decimal(string $name, string $label): self
    {
        return new self($name, $label, self::TYPE_DECIMAL);
    }

    /**
     * A boolean, rendered as the switch the old panel's Toggle draws. Defaults to false rather
     * than null: a checkbox that is not ticked is a decision, not a missing value.
     */
    public static function toggle(string $name, string $label): self
    {
        $field = new self($name, $label, self::TYPE_TOGGLE);
        $field->default = false;

        return $field;
    }

    /**
     * @param  array<string, string>  $options  value => label
     */
    public static function select(string $name, string $label, array $options): self
    {
        $field = new self($name, $label, self::TYPE_SELECT);
        $field->options = collect($options)
            ->map(fn (string $optionLabel, string $value) => ['value' => $value, 'label' => $optionLabel])
            ->values()
            ->all();

        return $field;
    }

    public function required(bool $required = true): self
    {
        $this->required = $required;

        return $this;
    }

    public function default(mixed $default): self
    {
        $this->default = $default;

        return $this;
    }

    public function help(string $help): self
    {
        $this->help = $help;

        return $this;
    }

    /**
     * Extra rules on top of the ones the type already implies, e.g. `min:0`.
     *
     * @param  array<int, mixed>  $rules
     */
    public function rules(array $rules): self
    {
        $this->rules = $rules;

        return $this;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function defaultValue(): mixed
    {
        return $this->default;
    }

    /**
     * The rules for `data.<name>`.
     *
     * Every field is validated on its own path, so a bad value is an error on that field
     * rather than one message about the whole document.
     *
     * @return array<int, mixed>
     */
    public function validationRules(): array
    {
        $rules = [$this->required ? 'required' : 'nullable'];

        $rules = [...$rules, ...match ($this->type) {
            self::TYPE_INTEGER => ['integer'],
            self::TYPE_DECIMAL => ['numeric'],
            // A toggle is never `required`: an unticked box would fail it.
            self::TYPE_TOGGLE => ['boolean'],
            self::TYPE_SELECT => ['string', Rule::in(array_column($this->options, 'value'))],
            default => ['string'],
        }];

        if ($this->type === self::TYPE_TOGGLE) {
            $rules[0] = 'nullable';
        }

        return [...$rules, ...$this->rules];
    }

    /**
     * A validated request value, or a value read back out of the database, cast to what
     * this field stores.
     *
     * Defensive about shapes it cannot use: a stored value of the wrong shape (an array
     * where the field declares a number, which is what an old hand-written JSON blob can
     * hold) falls back to the default rather than raising on the cast. The form shows the
     * raw stored document next to the fields, so the operator still sees the truth.
     */
    public function cast(mixed $value): mixed
    {
        if ($this->type === self::TYPE_TOGGLE) {
            return (bool) $value;
        }

        // '' is what an emptied text input posts, and it means "no value", not "the empty
        // string", for every optional field.
        if ($value === null || $value === '') {
            return $this->required ? $this->coerce($value) : null;
        }

        if (is_array($value) || is_object($value)) {
            return $this->default;
        }

        return $this->coerce($value);
    }

    private function coerce(mixed $value): mixed
    {
        return match ($this->type) {
            self::TYPE_INTEGER => (int) $value,
            self::TYPE_DECIMAL => (float) $value,
            default => (string) $value,
        };
    }

    /**
     * The declaration as the client renders it.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label,
            'type' => $this->type,
            'control' => match ($this->type) {
                self::TYPE_INTEGER, self::TYPE_DECIMAL => 'number',
                self::TYPE_TOGGLE => 'toggle',
                self::TYPE_SELECT => 'select',
                self::TYPE_TEXTAREA => 'textarea',
                default => 'text',
            },
            'step' => match ($this->type) {
                self::TYPE_INTEGER => '1',
                self::TYPE_DECIMAL => 'any',
                default => null,
            },
            'required' => $this->required,
            'default' => $this->default,
            'help' => $this->help,
            'options' => $this->options,
        ];
    }
}
