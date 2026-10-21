<?php

/*
 * This file is part of ernestdefoe/bouncer.
 */

namespace ErnestDefoe\Bouncer\Network;

/**
 * A set of IP ranges, merged and sorted so a lookup is one binary search.
 *
 * IPv4 bounds are integers; IPv6 bounds are "x" and 32 lowercase hex digits,
 * which sort the same way as the addresses they spell (the "x" stops PHP
 * comparing an all-digit string as a number). Exported as
 * plain arrays, a set loads from a PHP file that OPcache keeps in shared
 * memory, so a lookup costs microseconds and no I/O.
 */
class RangeSet
{
    /**
     * @param array{0: list<int>, 1: list<int>} $v4 starts, ends
     * @param array{0: list<string>, 1: list<string>} $v6 starts, ends
     */
    public function __construct(
        protected array $v4 = [[], []],
        protected array $v6 = [[], []],
    ) {
    }

    /**
     * Build from CIDRs or single addresses; anything unparseable is skipped.
     *
     * @param iterable<string> $cidrs
     */
    public static function fromCidrs(iterable $cidrs): self
    {
        $four = [];
        $six = [];

        foreach ($cidrs as $cidr) {
            $range = self::parse($cidr);

            if ($range === null) {
                continue;
            }

            if (is_int($range[0])) {
                $four[] = $range;
            } else {
                $six[] = $range;
            }
        }

        return new self(self::merge($four, fn (int $end) => $end + 1), self::merge($six, self::nextHex(...)));
    }

    /**
     * One entry per line (or comma); `#` starts a comment.
     */
    public static function fromText(string $text): self
    {
        return self::fromCidrs(self::lines($text));
    }

    /** @return list<string> */
    public static function lines(string $text): array
    {
        $out = [];

        foreach (preg_split('/[\r\n,]+/', $text) ?: [] as $line) {
            $line = trim(explode('#', $line, 2)[0]);

            if ($line !== '') {
                $out[] = $line;
            }
        }

        return $out;
    }

    /** @param array{v4?: mixed, v6?: mixed} $data */
    public static function fromArray(array $data): self
    {
        $valid = fn ($pair) => is_array($pair) && count($pair) === 2 && is_array($pair[0]) && is_array($pair[1]) && count($pair[0]) === count($pair[1]);

        return new self(
            $valid($data['v4'] ?? null) ? [array_values($data['v4'][0]), array_values($data['v4'][1])] : [[], []],
            $valid($data['v6'] ?? null) ? [array_values($data['v6'][0]), array_values($data['v6'][1])] : [[], []],
        );
    }

    /** @return array{v4: array{0: list<int>, 1: list<int>}, v6: array{0: list<string>, 1: list<string>}} */
    public function toArray(): array
    {
        return ['v4' => $this->v4, 'v6' => $this->v6];
    }

    public function count(): int
    {
        return count($this->v4[0]) + count($this->v6[0]);
    }

    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }

    public function contains(string $ip): bool
    {
        $key = self::key($ip);

        if ($key === null) {
            return false;
        }

        [$starts, $ends] = is_int($key) ? $this->v4 : $this->v6;

        // The last range starting at or before the address.
        $lo = 0;
        $hi = count($starts) - 1;
        $found = -1;

        while ($lo <= $hi) {
            $mid = ($lo + $hi) >> 1;

            if ($starts[$mid] <= $key) {
                $found = $mid;
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }

        return $found >= 0 && $ends[$found] >= $key;
    }

    /**
     * The sortable form of an address: an int for IPv4 (and IPv4-mapped
     * IPv6), "x" and 32 hex digits for IPv6, null if it isn't an address.
     */
    public static function key(string $ip): int|string|null
    {
        $packed = @inet_pton($ip);

        if ($packed === false) {
            return null;
        }

        if (strlen($packed) === 16 && str_starts_with($packed, "\0\0\0\0\0\0\0\0\0\0\xff\xff")) {
            $packed = substr($packed, 12);
        }

        if (strlen($packed) === 4) {
            return unpack('N', $packed)[1];
        }

        return 'x'.bin2hex($packed);
    }

    /** @return array{0: int, 1: int}|array{0: string, 1: string}|null */
    public static function parse(string $cidr): ?array
    {
        $cidr = trim($cidr);
        [$ip, $bits] = str_contains($cidr, '/') ? explode('/', $cidr, 2) : [$cidr, null];
        $key = self::key($ip);

        if ($key === null || ($bits !== null && ! ctype_digit($bits))) {
            return null;
        }

        if (is_int($key)) {
            // A mapped address written with a v6 prefix length counts its
            // last 32 bits.
            $width = str_contains($ip, ':') ? 128 : 32;
            $bits = $bits === null ? 32 : (int) $bits - ($width - 32);

            if ($bits < 0 || $bits > 32) {
                return null;
            }

            $mask = $bits === 0 ? 0 : (0xFFFFFFFF << (32 - $bits)) & 0xFFFFFFFF;

            return [$key & $mask, ($key & $mask) | (~$mask & 0xFFFFFFFF)];
        }

        $bits = $bits === null ? 128 : (int) $bits;

        if ($bits > 128) {
            return null;
        }

        $bytes = (string) hex2bin(substr($key, 1));
        $start = '';
        $end = '';

        for ($i = 0; $i < 16; $i++) {
            $keep = max(0, min(8, $bits - $i * 8));
            $mask = $keep === 0 ? 0 : (0xFF << (8 - $keep)) & 0xFF;
            $byte = ord($bytes[$i]);
            $start .= chr($byte & $mask);
            $end .= chr(($byte & $mask) | (~$mask & 0xFF));
        }

        return ['x'.bin2hex($start), 'x'.bin2hex($end)];
    }

    /**
     * Sort and coalesce overlapping or touching ranges.
     *
     * @template T of int|string
     * @param list<array{0: T, 1: T}> $ranges
     * @param callable(T): (T|null) $next the bound after an end, null past the top
     * @return array{0: list<T>, 1: list<T>}
     */
    protected static function merge(array $ranges, callable $next): array
    {
        usort($ranges, fn ($a, $b) => $a[0] <=> $b[0] ?: $b[1] <=> $a[1]);

        $starts = [];
        $ends = [];
        $last = -1;

        foreach ($ranges as [$start, $end]) {
            if ($last >= 0) {
                $after = $next($ends[$last]);

                if ($after === null || $start <= $after) {
                    if ($end > $ends[$last]) {
                        $ends[$last] = $end;
                    }

                    continue;
                }
            }

            $starts[] = $start;
            $ends[] = $end;
            $last++;
        }

        return [$starts, $ends];
    }

    protected static function nextHex(string $hex): ?string
    {
        if ($hex === 'x'.str_repeat('f', 32)) {
            return null;
        }

        $bytes = (string) hex2bin(substr($hex, 1));

        for ($i = 15; $i >= 0; $i--) {
            $byte = ord($bytes[$i]);

            if ($byte < 255) {
                $bytes[$i] = chr($byte + 1);

                break;
            }

            $bytes[$i] = "\0";
        }

        return 'x'.bin2hex($bytes);
    }
}
