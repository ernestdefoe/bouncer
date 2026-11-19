<?php

/*
 * This file is part of ernestdefoe/bouncer.
 */

namespace ErnestDefoe\Bouncer\Console;

use ErnestDefoe\Bouncer\Log;
use ErnestDefoe\Bouncer\Settings;
use Flarum\Console\AbstractCommand;

/**
 * Daily: deletes log rows older than the retention period. Its
 * dependencies stay cheap to build, for the reason given on
 * {@see NetworksCommand}.
 */
class PruneCommand extends AbstractCommand
{
    public function __construct(
        protected Log $log,
        protected Settings $settings,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('bouncer:prune')
            ->setDescription('Delete Bouncer log entries older than the retention period.');
    }

    protected function fire(): int
    {
        $deleted = $this->log->prune($this->settings->logDays());

        $this->info("Deleted $deleted log entries.");

        return 0;
    }
}
