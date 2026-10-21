<?php

/*
 * This file is part of ernestdefoe/bouncer.
 */

namespace ErnestDefoe\Bouncer\Network;

use ErnestDefoe\Bouncer\Settings;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;

/**
 * Rebuilds the network lists from their public sources: each data-centre
 * network's announced prefixes from RIPEstat (which sees the whole internet's
 * routing table, not just Europe's) and Cloudflare's published ranges.
 *
 * All or nothing: if any source fails, the current file stays as it was.
 */
class Updater
{
    /** Hosting and cloud networks, ASN => operator. Scrapers rent here; people rarely browse from here. */
    public const DEFAULT_ASNS = [
        16509 => 'Amazon AWS', 14618 => 'Amazon AWS', 396982 => 'Google Cloud',
        8075 => 'Microsoft Azure', 31898 => 'Oracle Cloud', 36351 => 'IBM Cloud',
        14061 => 'DigitalOcean', 63949 => 'Akamai / Linode', 20473 => 'Vultr',
        24940 => 'Hetzner', 213230 => 'Hetzner Cloud', 16276 => 'OVHcloud',
        12876 => 'Scaleway', 51167 => 'Contabo', 40021 => 'Contabo',
        60781 => 'Leaseweb', 28753 => 'Leaseweb', 30633 => 'Leaseweb', 7203 => 'Leaseweb',
        9009 => 'M247', 45102 => 'Alibaba Cloud', 37963 => 'Alibaba Cloud',
        132203 => 'Tencent Cloud', 45090 => 'Tencent Cloud', 136907 => 'Huawei Cloud',
        55990 => 'Huawei Cloud', 396986 => 'ByteDance', 138699 => 'ByteDance',
        47583 => 'Hostinger', 8560 => 'IONOS', 54290 => 'Hostwinds',
        36352 => 'ColoCrossing', 53667 => 'FranTech / BuyVM', 40676 => 'Psychz',
        21859 => 'Zenlayer', 63023 => 'GTHost', 197540 => 'netcup',
        210644 => 'Aeza', 49505 => 'Selectel', 9123 => 'Timeweb',
        54825 => 'Equinix Metal', 212238 => 'Datacamp', 19318 => 'InterServer',
        62240 => 'Clouvider', 26496 => 'GoDaddy', 398101 => 'GoDaddy',
        32244 => 'Liquid Web', 22612 => 'Namecheap', 33387 => 'Nocix',
        202425 => 'IP Volume', 13213 => 'THG Hosting', 46664 => 'VolumeDrive',
        35916 => 'Multacom', 23033 => 'Wowrack', 397423 => 'Tier.Net',
    ];

    public const RIPESTAT = 'https://stat.ripe.net/data/announced-prefixes/data.json?resource=AS%d&sourceapp=ernestdefoe-bouncer';

    public const CLOUDFLARE = ['https://www.cloudflare.com/ips-v4', 'https://www.cloudflare.com/ips-v6'];

    /** @var \Closure(list<string>): array<string, string|null> */
    protected \Closure $fetch;

    public function __construct(
        protected Settings $settings,
        protected Networks $networks,
    ) {
        $this->fetch = $this->fetchAll(...);
    }

    /**
     * Replace the HTTP fetcher (tests).
     *
     * @param \Closure(list<string>): array<string, string|null> $fetch url => body, null on failure
     */
    public function fetchWith(\Closure $fetch): void
    {
        $this->fetch = $fetch;
    }

    /** @return list<int> */
    public function asns(): array
    {
        $asns = array_diff(
            array_unique([...array_keys(self::DEFAULT_ASNS), ...$this->settings->extraAsns()]),
            $this->settings->ignoredAsns()
        );
        sort($asns);

        return $asns;
    }

    /**
     * Build the lists and write them to storage.
     *
     * @return array{ranges: int, cloudflare: int, asns: int}
     *
     * @throws \RuntimeException when a source fails; nothing is written
     */
    public function update(): array
    {
        $asns = $this->asns();
        $urls = array_map(fn (int $asn) => sprintf(self::RIPESTAT, $asn), $asns);
        $bodies = ($this->fetch)([...$urls, ...self::CLOUDFLARE]);

        $prefixes = [];

        foreach ($asns as $i => $asn) {
            $json = json_decode((string) ($bodies[$urls[$i]] ?? ''), true);
            $list = $json['data']['prefixes'] ?? null;

            if (! is_array($list) || ($json['status'] ?? '') !== 'ok') {
                throw new \RuntimeException("RIPEstat gave no prefixes for AS$asn");
            }

            foreach ($list as $row) {
                if (is_string($row['prefix'] ?? null)) {
                    $prefixes[] = $row['prefix'];
                }
            }
        }

        $cloudflare = [];

        foreach (self::CLOUDFLARE as $url) {
            $lines = RangeSet::lines((string) ($bodies[$url] ?? ''));
            $valid = array_filter($lines, fn ($line) => RangeSet::parse($line) !== null);

            if ($valid === [] || count($valid) !== count($lines)) {
                throw new \RuntimeException("Unexpected answer from $url");
            }

            array_push($cloudflare, ...$valid);
        }

        $datacentre = RangeSet::fromCidrs($prefixes);
        $cloudflareSet = RangeSet::fromCidrs($cloudflare);

        if ($datacentre->isEmpty()) {
            throw new \RuntimeException('No data-centre ranges found');
        }

        $this->write($this->networks->file(), [
            'updated' => time(),
            'asns' => $asns,
            'datacentre' => $datacentre->toArray(),
            'cloudflare' => $cloudflareSet->toArray(),
        ]);
        $this->networks->reset();

        return ['ranges' => $datacentre->count(), 'cloudflare' => $cloudflareSet->count(), 'asns' => count($asns)];
    }

    /** @param array<string, mixed> $data */
    public function write(string $file, array $data): void
    {
        $dir = dirname($file);

        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new \RuntimeException("Can't create $dir");
        }

        // Written to a temporary file and renamed, so a request never
        // includes half a file.
        $tmp = $file.'.'.bin2hex(random_bytes(4)).'.tmp';
        $php = "<?php\n\n// Written by ernestdefoe/bouncer (php flarum bouncer:networks). Do not edit.\n\nreturn ".self::export($data).";\n";

        if (file_put_contents($tmp, $php) === false || ! rename($tmp, $file)) {
            @unlink($tmp);

            throw new \RuntimeException("Can't write $file");
        }

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
    }

    /** var_export without a key on every list item: half the size, half the parse. */
    protected static function export(mixed $value): string
    {
        if (! is_array($value)) {
            return var_export($value, true);
        }

        $items = [];

        foreach ($value as $key => $item) {
            $items[] = (array_is_list($value) ? '' : var_export($key, true).'=>').self::export($item);
        }

        return '['.implode(',', $items).']';
    }

    /**
     * @param list<string> $urls
     * @return array<string, string|null>
     */
    protected function fetchAll(array $urls): array
    {
        $client = new Client([
            'timeout' => 30,
            'connect_timeout' => 10,
            'allow_redirects' => false,
            'headers' => ['User-Agent' => 'ernestdefoe-bouncer (+https://github.com/ernestdefoe/bouncer)'],
        ]);
        $out = array_fill_keys($urls, null);

        $requests = function () use ($urls) {
            foreach ($urls as $url) {
                yield $url => new Request('GET', $url);
            }
        };

        (new Pool($client, $requests(), [
            'concurrency' => 4,
            'fulfilled' => function (ResponseInterface $response, string $url) use (&$out) {
                if ($response->getStatusCode() === 200) {
                    $out[$url] = (string) $response->getBody();
                }
            },
        ]))->promise()->wait();

        return $out;
    }
}
