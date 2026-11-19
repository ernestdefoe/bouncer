<?php

/*
 * This file is part of ernestdefoe/bouncer.
 */

namespace ErnestDefoe\Bouncer;

use ErnestDefoe\Bouncer\Network\RangeSet;
use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Typed, validated access to Bouncer's settings. Flarum loads every setting
 * in one query per request already, so none of this touches the database.
 */
class Settings
{
    public const PREFIX = 'ernestdefoe-bouncer.';

    /** @var array<string, array{0: string, 1: mixed}> */
    protected array $memo = [];

    public function __construct(
        protected SettingsRepositoryInterface $settings
    ) {
    }

    /** Defaults come from extend.php's Extend\Settings, so none are repeated here. */
    public function get(string $key): mixed
    {
        return $this->settings->get(self::PREFIX.$key);
    }

    /**
     * The key challenges and passes are signed with, made on first use
     * through the settings repository (a cache in front of the settings
     * table, like fof/redis's, would hide a row a migration inserted).
     */
    public function secret(): string
    {
        $secret = (string) $this->get('secret');

        if (strlen($secret) < 32) {
            $secret = bin2hex(random_bytes(32));
            $this->settings->set(self::PREFIX.'secret', $secret);
        }

        return $secret;
    }

    /** "on" acts; "monitor" only logs what it would have done. */
    public function monitorOnly(): bool
    {
        return $this->get('mode') === 'monitor';
    }

    /** The header the reverse proxy puts the visitor's address in, if any. */
    public function ipHeader(): ?string
    {
        $value = (string) $this->get('ip_header');

        return in_array($value, ['x-forwarded-for', 'x-real-ip'], true) ? $value : null;
    }

    public function challengeEveryone(): bool
    {
        return (bool) $this->get('everyone');
    }

    public function guardApi(): bool
    {
        return (bool) $this->get('api');
    }

    /** challenge | block | off; a value that is none of these challenges. */
    public function action(string $signal): string
    {
        $value = (string) $this->get($signal);

        return in_array($value, ['challenge', 'block', 'off'], true) ? $value : 'challenge';
    }

    public function burstChallenge(): int
    {
        return max(0, (int) $this->get('burst_challenge'));
    }

    public function burstBlock(): int
    {
        return max(0, (int) $this->get('burst_block'));
    }

    public function difficulty(): int
    {
        return max(8, min(24, (int) $this->get('difficulty')));
    }

    public function passLifetime(): int
    {
        return max(1, min(90, (int) $this->get('pass_days'))) * 86400;
    }

    public function logDays(): int
    {
        return max(1, min(365, (int) $this->get('log_days')));
    }

    public function robots(): bool
    {
        return (bool) $this->get('robots');
    }

    public function aiTxt(): bool
    {
        return (bool) $this->get('ai_txt');
    }

    /** Overrides of each listed bot's default: key => allow|block. @return array<string, string> */
    public function botPolicies(): array
    {
        return $this->memo('bots', function (string $raw) {
            $raw = json_decode($raw, true);

            return is_array($raw)
                ? array_filter($raw, fn ($v, $k) => is_string($k) && in_array($v, ['allow', 'block'], true), ARRAY_FILTER_USE_BOTH)
                : [];
        });
    }

    public function botBlocked(string $key, bool $default): bool
    {
        $policy = $this->botPolicies()[$key] ?? null;

        return $policy === null ? $default : $policy === 'block';
    }

    public function allowedIps(): RangeSet
    {
        return $this->memo('allow_ips', RangeSet::fromText(...));
    }

    public function blockedIps(): RangeSet
    {
        return $this->memo('block_ips', RangeSet::fromText(...));
    }

    public function trustedProxies(): RangeSet
    {
        return $this->memo('trusted_proxies', RangeSet::fromText(...));
    }

    /** @return list<string> lowercase */
    public function allowedAgents(): array
    {
        return $this->memo('allow_agents', fn (string $raw) => array_map('strtolower', RangeSet::lines($raw)));
    }

    /** @return list<string> lowercase */
    public function blockedAgents(): array
    {
        return $this->memo('block_agents', fn (string $raw) => array_map('strtolower', RangeSet::lines($raw)));
    }

    /** Path prefixes never checked. @return list<string> */
    public function exemptPaths(): array
    {
        return $this->memo('exempt_paths', fn (string $raw) => array_values(array_filter(
            RangeSet::lines($raw),
            fn ($p) => str_starts_with($p, '/')
        )));
    }

    /** @return list<int> */
    public function extraAsns(): array
    {
        return self::asnList((string) $this->get('extra_asns'));
    }

    /** @return list<int> */
    public function ignoredAsns(): array
    {
        return self::asnList((string) $this->get('ignore_asns'));
    }

    /** @return array{site: string, secret: string}|null */
    public function turnstile(): ?array
    {
        if ($this->get('challenge') !== 'turnstile') {
            return null;
        }

        // The keys of a Turnstile extension already set up on this forum.
        foreach (['flectar-turnstile.', 'blomstra-turnstile.'] as $prefix) {
            $site = (string) $this->settings->get($prefix.'site_key');
            $secret = (string) $this->settings->get($prefix.'secret_key');

            if ($site !== '' && $secret !== '') {
                return ['site' => $site, 'secret' => $secret];
            }
        }

        return null;
    }

    /**
     * A value parsed from a setting, reparsed only when the setting changes
     * (the middleware holding this lives as long as the app).
     *
     * @template T
     * @param callable(string): T $parse
     * @return T
     */
    protected function memo(string $key, callable $parse): mixed
    {
        $raw = (string) $this->get($key);

        if (! isset($this->memo[$key]) || $this->memo[$key][0] !== $raw) {
            $this->memo[$key] = [$raw, $parse($raw)];
        }

        return $this->memo[$key][1];
    }

    /** @return list<int> */
    protected static function asnList(string $text): array
    {
        preg_match_all('/\d{1,10}/', $text, $m);

        return array_values(array_unique(array_filter(array_map('intval', $m[0]), fn ($n) => $n > 0 && $n < 4294967296)));
    }
}
