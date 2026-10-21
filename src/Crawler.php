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
 * A confirmed crawler is cached for a day, a refusal for an hour. A DNS
 * error is "unknown", never "fake", and isn't cached, so one resolver
 * hiccup can't lock a real crawler out. The forward lookup only happens
 * for names in the engine's own domain, so a reverse zone pointing at a
 * nameserver that never answers can't make two slow lookups out of one.
 * Lookups are capped per minute; over the cap the answer is "unknown".
 */
class Crawler
{
    public const VERIFIED = 'verified';

    public const FAKE = 'fake';

    public const UNKNOWN = 'unknown';

    public const LOOKUPS_PER_MINUTE = 30;

    /** @var \Closure(string): (string|false|null) hostname, false for "no name", null for a DNS error */
    protected \Closure $reverse;

    /** @var \Closure(string): list<string> */
    protected \Closure $forward;

    public function __construct(
        protected Cache $cache
    ) {
        $this->reverse = function (string $ip): string|false|null {
            $records = @dns_get_record(self::arpa($ip), DNS_PTR);

            if ($records === false) {
                return null;
            }

            return $records[0]['target'] ?? false;
        };
        $this->forward = function (string $host): array {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

            return array_values(array_filter(array_map(fn ($r) => $r['ip'] ?? $r['ipv6'] ?? null, $records)));
        };
    }

    /**
     * Replace the DNS lookups (tests).
     *
     * @param \Closure(string): (string|false|null) $reverse
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
        if (! ClientIp::isPublic($ip) || $suffixes === []) {
            return self::UNKNOWN;
        }

        $key = 'ernestdefoe-bouncer.dns.'.md5($ip.'|'.implode(',', $suffixes));
        $known = $this->cache->get($key);

        if ($known === self::VERIFIED || $known === self::FAKE) {
            return $known;
        }

        $budget = 'ernestdefoe-bouncer.dns-budget.'.intdiv(time(), 60);
        $this->cache->add($budget, 0, 120);

        if ((int) $this->cache->increment($budget) > self::LOOKUPS_PER_MINUTE) {
            return self::UNKNOWN;
        }

        $answer = $this->lookup($ip, $suffixes);

        if ($answer !== self::UNKNOWN) {
            $this->cache->put($key, $answer, $answer === self::VERIFIED ? 86400 : 3600);
        }

        return $answer;
    }

    /** @param list<string> $suffixes */
    protected function lookup(string $ip, array $suffixes): string
    {
        $host = ($this->reverse)($ip);

        if ($host === null) {
            return self::UNKNOWN;
        }

        $host = strtolower(rtrim((string) $host, '.'));

        if ($host === '' || ! self::inDomain($host, $suffixes)) {
            return self::FAKE;
        }

        $want = inet_pton($ip);

        foreach (($this->forward)($host) as $address) {
            if (@inet_pton($address) === $want) {
                return self::VERIFIED;
            }
        }

        return self::FAKE;
    }

    /** @param list<string> $suffixes */
    protected static function inDomain(string $host, array $suffixes): bool
    {
        foreach ($suffixes as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /** The reverse-lookup name: 4.3.2.1.in-addr.arpa, or the nibbles of an IPv6 address. */
    protected static function arpa(string $ip): string
    {
        $packed = (string) inet_pton($ip);

        if (strlen($packed) === 4) {
            return implode('.', array_reverse(explode('.', $ip))).'.in-addr.arpa';
        }

        return implode('.', array_reverse(str_split(bin2hex($packed)))).'.ip6.arpa';
    }
}
