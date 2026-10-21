<?php

/*
 * This file is part of ernestdefoe/bouncer.
 */

namespace ErnestDefoe\Bouncer;

use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Tells a real search engine from anything that only calls itself one, the
 * way the engines document: the address's reverse DNS name must end in one
 * of the engine's domains, and that name must resolve back to the address.
 *
 * Each address's answer is cached for a day, and lookups are capped per
 * minute so a flood of fake user agents can't keep PHP workers waiting on
 * DNS; over the cap the answer is "unknown", never "fake".
 */
class Crawler
{
    public const VERIFIED = 'verified';

    public const FAKE = 'fake';

    public const UNKNOWN = 'unknown';

    public const LOOKUPS_PER_MINUTE = 30;

    /** @var \Closure(string): (string|false) */
    protected \Closure $reverse;

    /** @var \Closure(string): list<string> */
    protected \Closure $forward;

    public function __construct(
        protected Cache $cache
    ) {
        $this->reverse = fn (string $ip) => @gethostbyaddr($ip);
        $this->forward = function (string $host): array {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

            return array_values(array_filter(array_map(fn ($r) => $r['ip'] ?? $r['ipv6'] ?? null, $records)));
        };
    }

    /**
     * Replace the DNS lookups (tests).
     *
     * @param \Closure(string): (string|false) $reverse
     * @param \Closure(string): list<string> $forward
     */
    public function resolveWith(\Closure $reverse, \Closure $forward): void
    {
        $this->reverse = $reverse;
        $this->forward = $forward;
    }

    /** @param list<string> $suffixes */
    public function verify(string $ip, array $suffixes): string
    {
        if (! ClientIp::isPublic($ip)) {
            return self::UNKNOWN;
        }

        $key = 'ernestdefoe-bouncer.dns.'.md5($ip);
        $host = $this->cache->get($key);

        if (! is_string($host)) {
            $budget = 'ernestdefoe-bouncer.dns-budget.'.intdiv(time(), 60);
            $this->cache->add($budget, 0, 120);

            if ((int) $this->cache->increment($budget) > self::LOOKUPS_PER_MINUTE) {
                return self::UNKNOWN;
            }

            $host = $this->lookup($ip);

            // The confirmed hostname, or '' for "doesn't confirm": both cached.
            $this->cache->put($key, $host, 86400);
        }

        foreach ($suffixes as $suffix) {
            if ($host !== '' && str_ends_with($host, $suffix)) {
                return self::VERIFIED;
            }
        }

        return self::FAKE;
    }

    protected function lookup(string $ip): string
    {
        $host = strtolower(rtrim((string) ($this->reverse)($ip), '.'));

        if ($host === '' || $host === $ip || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return '';
        }

        $want = inet_pton($ip);

        foreach (($this->forward)($host) as $address) {
            if (@inet_pton($address) === $want) {
                return $host;
            }
        }

        return '';
    }
}
