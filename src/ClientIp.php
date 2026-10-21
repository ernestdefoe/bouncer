<?php

/*
 * This file is part of ernestdefoe/bouncer.
 */

namespace ErnestDefoe\Bouncer;

use ErnestDefoe\Bouncer\Network\Networks;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The visitor's real address.
 *
 * Flarum takes REMOTE_ADDR as it is, which behind a reverse proxy (nginx in
 * front of a container, a load balancer) is the proxy for every visitor. So:
 * when the connection comes from a trusted proxy (a private or loopback
 * address, or one the admin lists), X-Forwarded-For is walked from the
 * right, skipping trusted hops, to the first address that isn't one; a
 * visitor can prepend whatever they like, but can't forge what the proxy
 * appended. CF-Connecting-IP is believed only when the hop that sent it is
 * in Cloudflare's published ranges.
 */
class ClientIp
{
    public function __construct(
        protected Settings $settings,
        protected Networks $networks,
    ) {
    }

    public function resolve(ServerRequestInterface $request): string
    {
        $server = $request->getServerParams();
        $ip = self::valid((string) ($server['REMOTE_ADDR'] ?? '')) ?? (string) $request->getAttribute('ipAddress', '');

        if ($ip !== '' && $this->trusted($ip)) {
            $hops = array_map('trim', explode(',', $request->getHeaderLine('X-Forwarded-For')));

            if ($request->getHeaderLine('X-Forwarded-For') === '') {
                $hops = [$request->getHeaderLine('X-Real-IP')];
            }

            foreach (array_reverse($hops) as $hop) {
                $hop = self::valid($hop);

                if ($hop === null) {
                    break;
                }

                $ip = $hop;

                if (! $this->trusted($hop) && ! $this->networks->cloudflare()->contains($hop)) {
                    break;
                }
            }
        }

        if ($this->networks->cloudflare()->contains($ip)) {
            $ip = self::valid($request->getHeaderLine('CF-Connecting-IP')) ?? $ip;
        }

        return $ip;
    }

    /** A public address: one the rate limits, networks and DNS checks mean something for. */
    public static function isPublic(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
            && ! self::inCgnat($ip);
    }

    public static function isLoopback(string $ip): bool
    {
        return $ip === '::1' || str_starts_with($ip, '127.');
    }

    protected function trusted(string $ip): bool
    {
        return ! self::isPublic($ip) || $this->settings->trustedProxies()->contains($ip);
    }

    protected static function inCgnat(string $ip): bool
    {
        $long = ip2long($ip);

        // 100.64.0.0/10, which PHP's filter doesn't count as private.
        return $long !== false && ($long & 0xFFC00000) === 0x64400000;
    }

    protected static function valid(string $ip): ?string
    {
        $ip = trim($ip);

        // "[2001:db8::1]:443" and "203.0.113.5:443" forms, as some proxies send them.
        if (preg_match('/^\[([0-9a-f:.]+)\](?::\d+)?$/i', $ip, $m) || preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $ip, $m)) {
            $ip = $m[1];
        }

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        // One spelling per address, so lists, logs and counters agree; an
        // IPv4-mapped IPv6 address is its IPv4 address.
        $packed = (string) inet_pton($ip);

        if (str_starts_with($packed, "\0\0\0\0\0\0\0\0\0\0\xff\xff")) {
            $packed = substr($packed, 12);
        }

        return (string) inet_ntop($packed);
    }
}
