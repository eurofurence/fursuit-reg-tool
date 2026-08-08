<?php

namespace App\Support\Manage;

use Closure;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Declares one preset view of a manage table.
 *
 * A tab is not a filter, and the difference is the whole reason this class exists rather
 * than a `pinned` flag on Filter. A filter is one narrowing an operator adds to whatever
 * they are already looking at, it lives on the toolbar next to the other narrowings, and
 * every filter a module declares can be on or off independently. A tab is the view itself:
 * exactly one is active at any moment, it is chosen before anything is refined, and the
 * chips then narrow within it. Rendering them as the same control makes "Admins" look like
 * something that can be combined with "Reviewers", which it cannot.
 *
 * The client renders what is declared here and nothing else, same as columns and filters,
 * so a module gains tabs by declaring them and no page changes.
 *
 * Request contract: `?tab=<key>`. The first declared tab is the default and is written
 * into the URL by its absence, not by `?tab=all`: the canonical link to the unnarrowed
 * list stays the bare URL, and the sixteen modules that declare no tabs keep the URLs they
 * have. An unknown key falls back to the first tab rather than erroring, so a stale or
 * hand-edited link still lands somewhere. TabBar.vue mirrors both rules, because it
 * resolves the active tab from the URL rather than from a prop; see the note there.
 *
 * @implements Arrayable<string, mixed>
 */
final class Tab implements Arrayable
{
    private ?Closure $apply = null;

    private bool $counted = false;

    private function __construct(
        public readonly string $key,
        public readonly string $label,
    ) {}

    public static function make(string $key, ?string $label = null): self
    {
        return new self($key, $label ?? str($key)->headline()->toString());
    }

    /**
     * How this tab narrows the table.
     *
     * A tab with no callback narrows nothing, which is what the leading "All" tab of every
     * tabbed module is: the whole table, declared so it can be selected and so the strip
     * reads as a complete set of views rather than a set of exceptions.
     *
     * @param  Closure(Builder): void  $callback
     */
    public function apply(Closure $callback): self
    {
        $this->apply = $callback;

        return $this;
    }

    /**
     * Whether to show how many records this tab holds.
     *
     * Opt-in per tab, and off by default, because a count is a COUNT query and the cost is
     * paid on every request to the list. It is worth it where the constraint is a plain
     * indexed predicate on the table already being listed, which is the Users case: three
     * `select count(*) from users where ...` with no joins. It is not worth it where the
     * tab narrows through a relation or a computed state, so those modules simply do not
     * ask, and their strip renders labels alone.
     *
     * The count is of the tab's own view of the table, before search and before the chip
     * filters. A count that moved with the filters would be answering a question nobody
     * asked ("admins matching the current chips") in the one place an operator looks to
     * decide where to go next, and it would have to be recomputed on every keystroke in
     * the search box.
     */
    public function counted(bool $counted = true): self
    {
        $this->counted = $counted;

        return $this;
    }

    public function isCounted(): bool
    {
        return $this->counted;
    }

    public function applyTo(Builder $query): void
    {
        if ($this->apply) {
            ($this->apply)($query);
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
        ];
    }
}
