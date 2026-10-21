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
 * front of a container, a load balancer) is the proxy for every visitor. So
 * the admin says which header their proxy sets, and only when the
 * connection comes from a trusted proxy (a private or loopback address, or
 * one the admin lists) is it read: X-Real-IP as it is, or X-Forwarded-For
 * walked from the right, skipping trusted hops, to the first address that
 * isn't one (a visitor can prepend whatever they like, but can't forge what
 * the proxy appended). CF-Connecting-IP is believed only when the hop that
 * sent it is in Cloudflare's published ranges.
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
        $header = $this->settings->ipHeader();

        if ($ip !== '' && $header !== null && $this->trusted($ip)) {
            // Only the header the admin chose: falling back from one to the
            // other would let a visitor supply whichever one the proxy
            // doesn't overwrite.
            $hops = $header === 'x-real-ip'
                ? [$request->getHeaderLine('X-Real-IP')]
                : array_map('trim', explode(',', $request->getHeaderLine('X-Forwarded-For')));

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

    /**
     * What one visitor's addresses have in common: the address itself for
     * IPv4, its /64 for IPv6 (one subscriber's whole network, which a client
     * can rotate through freely).
     */
    public static function bucket(string $ip): string
    {
        if (! str_contains($ip, ':')) {
            return $ip;
        }

        return bin2hex(substr((string) @inet_pton($ip), 0, 8)).'/64';
    }

    /** The network a pass is bound to: the /24 for IPv4, the /48 for IPv6. */
    public static function network(string $ip): string
    {
        $packed = @inet_pton($ip);

        if ($packed === false) {
            return '';
        }

        return bin2hex(strlen($packed) === 4 ? substr($packed, 0, 3) : substr($packed, 0, 6));
    }

    /** A public address: one the rate limits, networks and DNS checks mean something for. */
    public static function isPublic(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
            && ! self::inCgnat($ip);
    }

    /** Whether a proxy says it relayed this request for someone else. */
    public static function relayed(ServerRequestInterface $request): bool
    {
        return $request->hasHeader('X-Forwarded-For') || $request->hasHeader('X-Real-IP') || $request->hasHeader('CF-Connecting-IP') || $request->hasHeader('Forwarded');
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
