<?php

/*
 * This file is part of ernestdefoe/bouncer.
 */

namespace ErnestDefoe\Bouncer\Network;

use Flarum\Foundation\Paths;
use Illuminate\Contracts\Filesystem\Factory;

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

    /** The filesystem disk `bouncer:networks` writes to (storage/bouncer), and the file on it. */
    public const DISK = 'ernestdefoe-bouncer';

    public const FILE = 'networks.php';

    /** @var array<string, mixed>|null */
    protected ?array $data = null;

    protected ?RangeSet $datacentre = null;

    protected ?RangeSet $cloudflare = null;

    public function __construct(
        protected Paths $paths,
        protected Factory $filesystem,
    ) {
    }

    /** The disk's root, for its config in extend.php. */
    public static function directory(Paths $paths): string
    {
        return $paths->storage.'/bouncer';
    }

    /**
     * Read by path, not through the disk: `include` is what lets OPcache
     * serve the lists from shared memory, and it needs a real file.
     */
    public function file(): string
    {
        return self::directory($this->paths).'/'.self::FILE;
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
            'source' => $this->filesystem->disk(self::DISK)->exists(self::FILE) ? 'storage' : 'bundled',
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

        // By path (see file()); is_file() first, as include warns on a missing file.
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
