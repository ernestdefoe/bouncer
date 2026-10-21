<?php

/*
 * This file is part of ernestdefoe/bouncer.
 */

namespace ErnestDefoe\Bouncer\Console;

use ErnestDefoe\Bouncer\Log;
use ErnestDefoe\Bouncer\Settings;
use Flarum\Console\AbstractCommand;

/**
 * Daily: deletes log rows older than the retention period. No constructor
 * dependencies, for the reason given on {@see NetworksCommand}.
 */
class PruneCommand extends AbstractCommand
{
    protected function configure(): void
    {
        $this->setName('bouncer:prune')
            ->setDescription('Delete Bouncer log entries older than the retention period.');
    }

    protected function fire(): int
    {
        $deleted = resolve(Log::class)->prune(resolve(Settings::class)->logDays());

        $this->info("Deleted $deleted log entries.");

        return 0;
    }
}
