<?php

namespace App\Support\Manage;

use Illuminate\Contracts\Support\Arrayable;

/**
 * A button the client renders without knowing anything about the domain.
 *
 * Visibility is decided server-side (build the action or do not), which is what makes
 * "batch controls are offered only to an admin" assertable in a feature test.
 * A disabled action is still sent, carrying the reason, so the UI can explain itself,
 * which the Filament panel never did: it hid actions instead.
 *
 * Bulk actions POST an `ids[]` array. Guarded bulk operations are all-or-nothing, so the
 * controller either changes everything or nothing and says why.
 *
 * @implements Arrayable<string, mixed>
 */
final class Action implements Arrayable
{
    /**
     * Filament's own default confirm body, used verbatim wherever a resource never
     * overrode it, so the parity tests can assert on the copy.
     */
    public const DEFAULT_CONFIRM_DESCRIPTION = 'Are you sure you would like to do this?';

    private ?string $icon = null;

    private string $tone = Status::INFO;

    /** @var array{heading: string, description: ?string, submit: string}|null */
    private ?array $confirm = null;

    /** @var array<int, array<string, mixed>> */
    private array $fields = [];

    private ?string $disabledReason = null;

    private bool $newTab = false;

    private function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $url,
        public readonly string $method,
    ) {}

    public static function link(string $name, string $label, string $url): self
    {
        return new self($name, $label, $url, 'get');
    }

    public static function post(string $name, string $label, string $url): self
    {
        return new self($name, $label, $url, 'post');
    }

    public static function put(string $name, string $label, string $url): self
    {
        return new self($name, $label, $url, 'put');
    }

    public static function delete(string $name, string $label, string $url): self
    {
        return new self($name, $label, $url, 'delete');
    }

    public function icon(string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    public function tone(string $tone): self
    {
        $this->tone = $tone;

        return $this;
    }

    public function confirm(string $heading, ?string $description = null, string $submit = 'Confirm'): self
    {
        $this->confirm = [
            'heading' => $heading,
            'description' => $description,
            'submit' => $submit,
        ];

        return $this;
    }

    /**
     * A bare requiresConfirmation() in Filament: the action label as the heading, the
     * default body, and Confirm to submit.
     */
    public function confirmDefault(): self
    {
        return $this->confirm($this->label, self::DEFAULT_CONFIRM_DESCRIPTION);
    }

    /**
     * Filament's DeleteAction copy. `$label` is the record label the heading names.
     */
    public function confirmDelete(string $label): self
    {
        return $this->confirm('Delete '.$label, self::DEFAULT_CONFIRM_DESCRIPTION, 'Delete');
    }

    /**
     * Fields to collect in a modal before submitting, e.g. the reason on "Pause batch" or
     * the printer select on "Print Selected Badges".
     *
     * Shape per field: ['key', 'label', 'type', 'options'?, 'default'?, 'required'?,
     * 'maxLength'?, 'helper'?]
     *
     * @param  array<int, array<string, mixed>>  $fields
     */
    public function fields(array $fields): self
    {
        $this->fields = $fields;

        return $this;
    }

    public function disabled(?string $reason): self
    {
        $this->disabledReason = $reason;

        return $this;
    }

    public function newTab(bool $newTab = true): self
    {
        $this->newTab = $newTab;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label,
            'url' => $this->url,
            'method' => $this->method,
            'icon' => $this->icon,
            'tone' => $this->tone,
            'confirm' => $this->confirm,
            'fields' => $this->fields === [] ? null : $this->fields,
            'disabledReason' => $this->disabledReason,
            'newTab' => $this->newTab,
        ];
    }
}
