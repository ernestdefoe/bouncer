<?php

/*
 * This file is part of ernestdefoe/bouncer.
 */

namespace ErnestDefoe\Bouncer;

use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Requests per address per minute.
 *
 * In APCu when PHP has it (shared memory: atomic, no I/O, expires by
 * itself), otherwise in Flarum's cache: an atomic increment on Redis or
 * Memcached; on the file cache one small file per address, overwritten,
 * rather than a new file per address every minute.
 */
class Counter
{
    public const WINDOW = 60;

    protected bool $apcu;

    public function __construct(
        protected Cache $cache
    ) {
        $this->apcu = function_exists('apcu_enabled') && apcu_enabled();
    }

    /** Count this request; returns the address's count in the current minute. */
    public function hit(string $ip): int
    {
        $window = intdiv(time(), self::WINDOW);

        if ($this->apcu) {
            $key = 'ernestdefoe-bouncer.rate.'.$window.'.'.$ip;
            $count = apcu_inc($key, 1, $ok, self::WINDOW * 2);

            if ($count !== false) {
                return $count;
            }
        }

        if (! $this->cache instanceof CacheRepository || ! $this->cache->getStore() instanceof FileStore) {
            $key = 'ernestdefoe-bouncer.rate.'.$window.'.'.md5($ip);
            $this->cache->add($key, 0, self::WINDOW * 2);

            return (int) $this->cache->increment($key);
        }

        $key = 'ernestdefoe-bouncer.rate.'.md5($ip);
        $entry = $this->cache->get($key);
        $count = is_array($entry) && ($entry[0] ?? null) === $window ? (int) $entry[1] + 1 : 1;
        $this->cache->put($key, [$window, $count], self::WINDOW * 2);

        return $count;
    }
}
