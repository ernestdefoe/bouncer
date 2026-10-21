<?php

/*
 * This file is part of ernestdefoe/bouncer.
 */

namespace ErnestDefoe\Bouncer\Network;

use Flarum\Foundation\Paths;

/**
 * The data-centre and Cloudflare ranges: the copy `bouncer:networks` last
 * wrote to storage, or the snapshot shipped with the extension.
 *
 * Both are PHP files returning plain arrays, so with OPcache a request that
 * needs them reads shared memory instead of parsing anything.
 */
class Networks
{
    public const BUNDLED = __DIR__.'/../../resources/networks.php';

    /** @var array<string, mixed>|null */
    protected ?array $data = null;

    protected ?RangeSet $datacentre = null;

    protected ?RangeSet $cloudflare = null;

    public function __construct(
        protected Paths $paths
    ) {
    }

    public function file(): string
    {
        return $this->paths->storage.'/bouncer/networks.php';
    }

    public function datacentre(): RangeSet
    {
        return $this->datacentre ??= RangeSet::fromArray((array) ($this->data()['datacentre'] ?? []));
    }

    public function cloudflare(): RangeSet
    {
        return $this->cloudflare ??= RangeSet::fromArray((array) ($this->data()['cloudflare'] ?? []));
    }

    /** @return array{updated: int, asns: list<int>, source: string, ranges: int} */
    public function info(): array
    {
        $data = $this->data();

        return [
            'updated' => (int) ($data['updated'] ?? 0),
            'asns' => array_values(array_map('intval', (array) ($data['asns'] ?? []))),
            'source' => is_file($this->file()) ? 'storage' : 'bundled',
            'ranges' => $this->datacentre()->count(),
        ];
    }

    /** Forget what this process loaded, after a refresh. */
    public function reset(): void
    {
        $this->data = $this->datacentre = $this->cloudflare = null;
    }

    /** @return array<string, mixed> */
    protected function data(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }

        foreach ([$this->file(), self::BUNDLED] as $file) {
            if (is_file($file)) {
                $data = include $file;

                if (is_array($data) && isset($data['datacentre'], $data['cloudflare'])) {
                    return $this->data = $data;
                }
            }
        }

        return $this->data = [];
    }
}
