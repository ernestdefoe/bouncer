<?php

/*
 * This file is part of ernestdefoe/bouncer.
 */

namespace ErnestDefoe\Bouncer;

use Flarum\Extension\ExtensionManager;
use Flarum\Http\UrlGenerator;

/**
 * robots.txt and ai.txt that say what Bouncer enforces, so well-behaved
 * crawlers don't need turning away at all.
 */
class Robots
{
    public const MARKER = '# Bouncer: AI crawlers this forum turns away';

    public function __construct(
        protected Settings $settings,
        protected UrlGenerator $url,
        protected ExtensionManager $extensions,
    ) {
    }

    /** robots.txt tokens of every bot the admin blocks. @return list<string> */
    public function blockedTokens(): array
    {
        $tokens = [];

        foreach (Agents::BOTS as $key => $bot) {
            if ($this->settings->botBlocked($key, $bot[3])) {
                array_push($tokens, ...$bot[5]);
            }
        }

        return array_values(array_unique($tokens));
    }

    /**
     * Our group, after whatever another extension serves (or a default that
     * lets everyone else in).
     */
    public function robotsTxt(?string $existing): string
    {
        $base = $existing !== null && trim($existing) !== '' ? rtrim($existing)."\n" : $this->defaultRobots();
        $tokens = $this->blockedTokens();

        if ($tokens === [] || str_contains($base, self::MARKER)) {
            return $base;
        }

        $group = self::MARKER."\n";

        foreach ($tokens as $token) {
            $group .= "User-agent: $token\n";
        }

        return $base."\n".$group."Disallow: /\n";
    }

    /** Spawning's ai.txt: opts the whole site out of AI training. */
    public function aiTxt(): string
    {
        return "# ai.txt (https://site.spawning.ai/spawning-ai-txt)\n# Written by Bouncer: this forum's content is not offered for AI training.\nUser-Agent: *\nDisallow: /\n";
    }

    protected function defaultRobots(): string
    {
        $out = "User-agent: *\nDisallow:\n";

        if ($this->extensions->isEnabled('fof-sitemap')) {
            $out .= "\nSitemap: ".$this->url->to('forum')->base()."/sitemap.xml\n";
        }

        return $out;
    }
}
