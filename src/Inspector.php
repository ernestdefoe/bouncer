<?php

/*
 * This file is part of ernestdefoe/bouncer.
 */

namespace ErnestDefoe\Bouncer;

use ErnestDefoe\Bouncer\Network\Networks;
use Flarum\Foundation\Config;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Decides what happens to a guest's request. Cheapest checks first; the only
 * I/O an ordinary visitor costs is one counter increment (APCu or cache).
 *
 * Order matters:
 *  1. staff allow/block lists (addresses, then user agents);
 *  2. search engines, verified by DNS: real ones always pass, fakes are
 *     turned away; never rate limited, never challenged;
 *  3. listed bots the admin blocks;
 *  4. the per-address rate count, and the hard limit;
 *  5. listed bots the admin allows, link previews, monitors, feed readers;
 *  6. a valid pass (a challenge solved earlier);
 *  7. the signals that earn a challenge: everyone (under attack), no user
 *     agent, HTTP libraries and headless browsers, missing browser headers,
 *     data-centre networks, bursts.
 */
class Inspector
{
    public function __construct(
        protected Settings $settings,
        protected Crawler $crawler,
        protected Counter $counter,
        protected Tokens $tokens,
        protected Networks $networks,
        protected Config $config,
    ) {
    }

    public function inspect(Request $request, string $ip, ?string $pass, bool $api): Verdict
    {
        $settings = $this->settings;
        $ua = $request->getHeaderLine('User-Agent');
        $lower = strtolower($ua);
        $public = ClientIp::isPublic($ip);

        if ($settings->allowedIps()->contains($ip)) {
            return Verdict::pass('allowed_ip');
        }

        if ($settings->blockedIps()->contains($ip)) {
            return new Verdict(Verdict::BLOCK, 'blocked_ip', null, true);
        }

        foreach ($settings->allowedAgents() as $token) {
            if (str_contains($lower, $token)) {
                return Verdict::pass('allowed_agent');
            }
        }

        foreach ($settings->blockedAgents() as $token) {
            if (str_contains($lower, $token)) {
                return new Verdict(Verdict::BLOCK, 'blocked_agent', $token, true);
            }
        }

        $agent = Agents::identify($ua);
        $unverified = false;

        if ($agent['type'] === 'engine') {
            $check = $this->crawler->verify($ip, $agent['suffixes'] ?? []);

            if ($check === Crawler::VERIFIED) {
                return Verdict::pass('engine', $agent['name'], true);
            }

            if ($check === Crawler::FAKE && ($action = $this->signal('fake_engines', 'block'))) {
                return new Verdict($action, 'fake_engine', $agent['name'], true);
            }

            // DNS couldn't say (lookup budget spent, or no public address to
            // look up): it may be the real thing, so only the rate limits
            // apply below.
            $unverified = $check === Crawler::UNKNOWN;
        }

        if ($agent['type'] === 'bot' && $settings->botBlocked($agent['key'], Agents::botBlockedByDefault($agent['key']))) {
            return new Verdict(Verdict::BLOCK, 'bot', $agent['name'], true);
        }

        $count = 0;

        if ($public && ($settings->burstBlock() > 0 || $settings->burstChallenge() > 0)) {
            $count = $this->counter->hit($ip);

            if ($settings->burstBlock() > 0 && $count > $settings->burstBlock()) {
                return new Verdict(Verdict::LIMIT, 'burst', $agent['name'] ?: null, true);
            }
        }

        if ($agent['type'] === 'bot') {
            return Verdict::pass('bot', $agent['name'], true);
        }

        if ($agent['type'] === 'friendly' || $unverified) {
            // A link preview or a monitor never fetches pages in bursts;
            // something borrowing its name might.
            if ($agent['type'] === 'friendly' && $settings->burstChallenge() > 0 && $count > $settings->burstChallenge()) {
                return new Verdict(Verdict::CHALLENGE, 'burst', $agent['name'], true);
            }

            return Verdict::pass($unverified ? 'engine_unverified' : 'friendly');
        }

        // A day's grace on the API: a page left open past its pass still loads more.
        if ($this->tokens->validPass($pass, $ua, $api ? 86400 : 0)) {
            return Verdict::pass('pass');
        }

        if ($settings->challengeEveryone()) {
            return new Verdict(Verdict::CHALLENGE, 'everyone');
        }

        if (($agent['type'] === 'empty' || $agent['type'] === 'tool') && ($action = $this->signal('tools'))) {
            return new Verdict($action, $agent['type'] === 'empty' ? 'no_agent' : 'tool', $agent['name'] ?: null, true);
        }

        if ($agent['type'] === 'browser' && $this->missingBrowserHeaders($request, $ua) && ($action = $this->signal('headers'))) {
            return new Verdict($action, 'headers', null, true);
        }

        if ($public && ($action = $this->signal('datacentre')) && $this->networks->datacentre()->contains($ip)) {
            return new Verdict($action, 'datacentre', null, true);
        }

        if ($settings->burstChallenge() > 0 && $count > $settings->burstChallenge()) {
            return new Verdict(Verdict::CHALLENGE, 'burst', null, true);
        }

        return Verdict::pass('none');
    }

    /**
     * Every browser sends Accept-Language; and one claiming to be a recent
     * Chrome, Edge or Firefox sends Sec-Fetch-Mode to an HTTPS site.
     */
    protected function missingBrowserHeaders(Request $request, string $ua): bool
    {
        if ($request->getHeaderLine('Accept-Language') === '') {
            return true;
        }

        return $this->config->url()->getScheme() === 'https'
            && Agents::claimsFetchMetadata($ua)
            && $request->getHeaderLine('Sec-Fetch-Mode') === '';
    }

    /** The admin's action for a signal: challenge or block, null when it's off. */
    protected function signal(string $name, string $default = 'challenge'): ?string
    {
        $action = $this->settings->action($name, $default);

        return $action === 'off' ? null : $action;
    }
}
