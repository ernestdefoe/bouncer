<?php

/*
 * This file is part of ernestdefoe/bouncer.
 */

namespace ErnestDefoe\Bouncer\Search;

use ErnestDefoe\Bouncer\LogEntry;
use Flarum\Search\Database\AbstractSearcher;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Searches the staff log. Only admins see any of it; the endpoint checks
 * that too, this keeps it true for any other caller.
 */
class LogSearcher extends AbstractSearcher
{
    /** @return Builder<LogEntry> */
    public function getQuery(User $actor): Builder
    {
        $query = LogEntry::query();

        return $actor->isAdmin() ? $query : $query->whereKey([]);
    }
}
