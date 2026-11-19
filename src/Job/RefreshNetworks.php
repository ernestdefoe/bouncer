<?php

/*
 * This file is part of ernestdefoe/bouncer.
 */

namespace ErnestDefoe\Bouncer\Job;

use ErnestDefoe\Bouncer\Network\Networks;
use ErnestDefoe\Bouncer\Network\Updater;
use Flarum\Queue\AbstractJob;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * The admin's "Refresh now": rebuilds the network lists on the queue (or,
 * with no queue worker, from the scheduler: `bouncer:networks --requested`),
 * so the request that asked returns at once. The outcome is left in the
 * cache for the admin page, which polls for it.
 */
class RefreshNetworks extends AbstractJob
{
    /** Set while a refresh is queued or running; released when it ends. */
    public const LOCK = 'ernestdefoe-bouncer.refreshing';

    /** Set when the queue is synchronous: the scheduler runs the refresh instead. */
    public const REQUESTED = 'ernestdefoe-bouncer.refresh-requested';

    /** The last refresh's outcome: state running, done or failed. */
    public const STATE = 'ernestdefoe-bouncer.refresh';

    /** Seconds a lock or a "running" state may outlive a worker that died. */
    public const TTL = 300;

    /** The sources' own deadline ends a run within about 150 seconds. */
    public int $timeout = 180;

    public int $tries = 1;

    public function handle(Updater $updater, Networks $networks, Cache $cache): void
    {
        try {
            $this->finish($cache, ['state' => 'done', 'result' => $updater->update(), 'networks' => $networks->info()]);
        } catch (\Throwable $e) {
            // A source that failed is the admin's answer; anything else is
            // also reported to the queue's failed-job log.
            $this->finish($cache, ['state' => 'failed', 'error' => $e->getMessage()]);

            if (! $e instanceof \RuntimeException) {
                throw $e;
            }
        }
    }

    /** @param array<string, mixed> $state */
    protected function finish(Cache $cache, array $state): void
    {
        $cache->put(self::STATE, $state + ['at' => time()], self::TTL);
        $cache->forget(self::LOCK);
    }
}
