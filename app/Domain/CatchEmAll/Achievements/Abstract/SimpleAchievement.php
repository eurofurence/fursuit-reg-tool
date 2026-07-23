<?php

namespace App\Domain\CatchEmAll\Achievements\Abstract;

use App\Domain\CatchEmAll\Interface\Achievement;

abstract class SimpleAchievement implements Achievement
{
    /**
     * Summary of __construct
     *
     * @param  string|null  $task  Will be set to description if null
     */
    public function __construct(string $id, string $title, string $description = '', ?string $task = null, string $icon = '', bool $isSecret = false, bool $isOptional = false, bool $isHidden = false)
    {
        $this->id = $id;
        $this->title = $title;
        $this->description = $description;
        $this->task = $task ?? $description;
        $this->icon = $icon;
        $this->isSecret = $isSecret;
        $this->isOptional = $isOptional;
        $this->isHidden = $isHidden;
    }

    protected string $id;

    protected string $title;

    protected string $description;

    protected string $task;

    protected string $icon;

    protected bool $isSecret;

    protected bool $isOptional;

    protected bool $isHidden;

    public function getId(): string
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getTask(): string
    {
        return $this->task;
    }

    public function getIcon(): string
    {
        return $this->icon;
    }

    public function isSecret(): bool
    {
        return $this->isSecret;
    }

    public function isOptional(): bool
    {
        return $this->isOptional;
    }

    public function isHidden(): bool
    {
        return $this->isHidden;
    }
}
