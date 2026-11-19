<?php

/*
 * This file is part of ernestdefoe/bouncer.
 */

namespace ErnestDefoe\Bouncer\Search;

use ErnestDefoe\Bouncer\LogEntry;
use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\Filter\FilterInterface;
use Flarum\Search\SearchState;
use Flarum\Search\ValidateFilterTrait;

/**
 * filter[action]=blocked: log rows with one of Bouncer's actions. Anything
 * else matches nothing.
 *
 * @implements FilterInterface<DatabaseSearchState>
 */
class ActionFilter implements FilterInterface
{
    use ValidateFilterTrait;

    public function getFilterKey(): string
    {
        return 'action';
    }

    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        $action = $this->asString($value);

        if (! in_array($action, LogEntry::ACTIONS, true)) {
            $state->getQuery()->whereKey([]);

            return;
        }

        $state->getQuery()->where('action', $negate ? '!=' : '=', $action);
    }
}
