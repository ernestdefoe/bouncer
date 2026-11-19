<?php

/*
 * This file is part of ernestdefoe/bouncer.
 */

namespace ErnestDefoe\Bouncer\Console;

use ErnestDefoe\Bouncer\Job\RefreshNetworks;
use ErnestDefoe\Bouncer\Network\Networks;
use ErnestDefoe\Bouncer\Network\Updater;
use Flarum\Console\AbstractCommand;
use Illuminate\Contracts\Cache\Repository as Cache;
use Symfony\Component\Console\Input\InputOption;

/**
 * Weekly: refreshes the data-centre and Cloudflare ranges. Every minute,
 * with --requested: runs an admin's "Refresh now" on a forum with no queue
 * worker, if one is waiting ({@see \ErnestDefoe\Bouncer\Api\BouncerResource}).
 *
 * The scheduler builds every scheduled command when the app boots, so what
 * this is given must stay cheap to build: nothing here does any work until
 * the command runs (about 5 µs a request, measured).
 */
class NetworksCommand extends AbstractCommand
{
    public function __construct(
        protected Updater $updater,
        protected Networks $networks,
        protected Cache $cache,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('bouncer:networks')
            ->setDescription('Refresh Bouncer\'s data-centre and Cloudflare network lists.')
            ->addOption('requested', null, InputOption::VALUE_NONE, 'Only if an admin asked for a refresh (run every minute by the scheduler).');
    }

    protected function fire(): int
    {
        if ($this->input->getOption('requested')) {
            return $this->requested();
        }

        try {
            $result = $this->updater->update();
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage().' — the current lists are kept.');

            return 1;
        }

        $this->info("{$result['ranges']} data-centre ranges from {$result['asns']} networks, {$result['cloudflare']} Cloudflare ranges.");

        return 0;
    }

    /** The admin's refresh, if one is waiting: run as the queue would run it. */
    protected function requested(): int
    {
        if (! $this->cache->pull(RefreshNetworks::REQUESTED)) {
            return 0;
        }

        (new RefreshNetworks())->handle($this->updater, $this->networks, $this->cache);
        $state = (array) $this->cache->get(RefreshNetworks::STATE);

        if (($state['state'] ?? '') !== 'done') {
            $this->error((string) ($state['error'] ?? 'Refresh failed').' — the current lists are kept.');

            return 1;
        }

        $this->info('Refreshed for the admin page.');

        return 0;
    }
}
