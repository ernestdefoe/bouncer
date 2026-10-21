<?php

/*
 * This file is part of ernestdefoe/bouncer.
 */

namespace ErnestDefoe\Bouncer\Console;

use ErnestDefoe\Bouncer\Network\Updater;
use Flarum\Console\AbstractCommand;

/**
 * Weekly: refreshes the data-centre and Cloudflare ranges.
 *
 * No constructor dependencies: Flarum builds every scheduled command when
 * the app boots, so anything injected here would be built on every request.
 */
class NetworksCommand extends AbstractCommand
{
    protected function configure(): void
    {
        $this->setName('bouncer:networks')
            ->setDescription('Refresh Bouncer\'s data-centre and Cloudflare network lists.');
    }

    protected function fire(): int
    {
        try {
            $result = resolve(Updater::class)->update();
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage().' — the current lists are kept.');

            return 1;
        }

        $this->info("{$result['ranges']} data-centre ranges from {$result['asns']} networks, {$result['cloudflare']} Cloudflare ranges.");

        return 0;
    }
}
