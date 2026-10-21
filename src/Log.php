<?php

/*
 * This file is part of ernestdefoe/bouncer.
 */

namespace ErnestDefoe\Bouncer;

use Carbon\Carbon;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;

/**
 * The staff log: one row per address, action and reason per ten minutes,
 * with a hit count, so a bot hammering the forum writes a handful of rows,
 * not one per request. New rows are capped per day, so a botnet of many
 * addresses can't grow the table without bound. Ordinary visitors are never
 * logged.
 */
class Log
{
    public const TABLE = 'bouncer_log';

    public const WINDOW = 600;

    public const ROWS_PER_DAY = 5000;

    public function __construct(
        protected ConnectionInterface $db,
        protected Cache $cache,
    ) {
    }

    public function record(string $ip, string $userAgent, string $path, string $action, string $reason, ?string $bot = null): void
    {
        // One row per IPv6 /64, which a client can rotate through freely.
        $key = 'ernestdefoe-bouncer.log.'.md5(ClientIp::bucket($ip)."\n".$action."\n".$reason);
        $entry = $this->cache->get($key);
        $now = time();

        if (is_array($entry) && is_int($entry['id'] ?? null)) {
            $entry['hits']++;

            // Every one of the first ten hits, then at most once a minute.
            $write = $entry['hits'] <= 10 || $now - $entry['written'] >= 60;

            // No row to update means staff cleared the log: start a new one.
            if (! $write || $this->db->table(self::TABLE)->where('id', $entry['id'])->update([
                'hits' => $entry['hits'],
                'last_seen' => Carbon::createFromTimestamp($now),
            ]) > 0) {
                $entry['written'] = $write ? $now : $entry['written'];
                $this->cache->put($key, $entry, self::WINDOW);

                return;
            }
        }

        $today = 'ernestdefoe-bouncer.log-rows.'.date('Ymd', $now);
        $this->cache->add($today, 0, 90000);

        if ((int) $this->cache->increment($today) > self::ROWS_PER_DAY) {
            return;
        }

        $at = Carbon::createFromTimestamp($now);
        $id = $this->db->table(self::TABLE)->insertGetId([
            'ip' => $ip,
            'user_agent' => mb_substr($userAgent, 0, 255),
            'action' => $action,
            'reason' => $reason,
            'bot' => $bot === null ? null : mb_substr($bot, 0, 64),
            'path' => mb_substr($path, 0, 255),
            'hits' => 1,
            'first_seen' => $at,
            'last_seen' => $at,
        ]);

        $this->cache->put($key, ['id' => (int) $id, 'hits' => 1, 'written' => $now], self::WINDOW);
    }

    /** Delete rows older than the retention period; returns how many. */
    public function prune(int $days): int
    {
        return $this->db->table(self::TABLE)->where('last_seen', '<', Carbon::now()->subDays($days))->delete();
    }
}
