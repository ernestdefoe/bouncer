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

    /**
     * Hits by action for each of the last seven days (UTC), oldest first.
     * One grouped query: a sum per day, each day a CASE column (raw, as the
     * builder has no conditional sum; portable to every database).
     *
     * @return list<array{date: string, counts: array<string, int>}>
     */
    public function week(): array
    {
        $starts = [];
        $columns = [];
        $bindings = [];

        for ($i = 6; $i >= 0; $i--) {
            $start = Carbon::now('UTC')->startOfDay()->subDays($i);
            $starts[] = $start;
            $columns[] = 'sum(case when last_seen >= ? and last_seen < ? then hits else 0 end) as d'.(6 - $i);
            array_push($bindings, $start, $start->copy()->addDay());
        }

        $rows = $this->db->table(self::TABLE)
            ->where('last_seen', '>=', $starts[0])
            ->groupBy('action')
            ->selectRaw('action, '.implode(', ', $columns), $bindings)
            ->get();

        $days = [];

        foreach ($starts as $i => $start) {
            $counts = [];

            foreach ($rows as $row) {
                if ((int) $row->{'d'.$i} > 0) {
                    $counts[$row->action] = (int) $row->{'d'.$i};
                }
            }

            $days[] = ['date' => $start->toDateString(), 'counts' => $counts];
        }

        return $days;
    }

    /**
     * The 15 busiest named bots of the last seven days.
     *
     * @return list<array{bot: string, action: string, reason: string, hits: int}>
     */
    public function topBots(): array
    {
        return $this->db->table(self::TABLE)
            ->whereNotNull('bot')
            ->where('last_seen', '>=', Carbon::now('UTC')->subDays(7))
            ->groupBy('bot', 'action', 'reason')
            // Raw only for the sum, which the builder can't alias.
            ->selectRaw('bot, action, reason, sum(hits) as hits')
            ->orderByDesc('hits')
            ->limit(15)
            ->get()
            ->map(fn ($row) => ['bot' => $row->bot, 'action' => $row->action, 'reason' => $row->reason, 'hits' => (int) $row->hits])
            ->values()
            ->all();
    }
}
