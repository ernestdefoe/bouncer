<?php

/*
 * This file is part of ernestdefoe/bouncer.
 */

namespace ErnestDefoe\Bouncer\Search;

use ErnestDefoe\Bouncer\Log;
use Flarum\Search\AbstractFulltextFilter;
use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\SearchState;
use Illuminate\Database\Eloquent\Builder;

/**
 * filter[q]: a part of an address, user agent or bot name.
 *
 * A contains match can't use an index. The table is small by design
 * ({@see Log}: one row per address and action per ten minutes, at most
 * 5,000 new rows a day, pruned daily), and only admins search it.
 *
 * @extends AbstractFulltextFilter<DatabaseSearchState>
 */
class LogFulltext extends AbstractFulltextFilter
{
    public function search(SearchState $state, string $value): void
    {
        $value = trim($value);

        if ($value === '') {
            return;
        }

        // Case-insensitive on every database, with % and _ taken literally:
        // "!" is the escape character, as it means nothing special in any of
        // their string literals.
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(mb_substr($value, 0, 100))).'%';

        $state->getQuery()->where(function (Builder $query) use ($like) {
            foreach (['ip', 'user_agent', 'bot'] as $column) {
                $query->orWhereRaw('lower('.$query->getQuery()->getGrammar()->wrap($column).") like ? escape '!'", [$like]);
            }
        });
    }
}
