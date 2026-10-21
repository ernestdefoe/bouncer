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
 *  1. the server itself (loopback), then staff allow/block lists (addresses, then user agents);
 *  2. search engines, verified by DNS: real ones always pass, fakes are
 *     turned away; never rate limited, never challenged;
 *  3. listed bots the admin blocks;
 *  4. the rate count (per pass, or per address) and the hard limit;
 *  5. listed bots the admin allows, link previews, monitors, feed readers:
 *     let in below the burst threshold;
 *  6. a valid pass (a challenge solved earlier, on this network);
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

        // The server talking to itself: health checks, cache warmers, cron.
        if (ClientIp::isLoopback($ip) || $settings->allowedIps()->contains($ip)) {
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

            // DNS couldn't vouch for it (lookup budget spent): it may be real,
            // so no header check (crawlers don't send browser headers), but
            // the data-centre and burst checks below still apply.
            $unverified = $check === Crawler::UNKNOWN;
        }

        if ($agent['type'] === 'bot' && $settings->botBlocked($agent['key'], Agents::botBlockedByDefault($agent['key']))) {
            return new Verdict(Verdict::BLOCK, 'bot', $agent['name'], true);
        }

        // A day's grace on the API: a page left open past its pass still loads more.
        $passId = $this->tokens->validPass($pass, $ua, ClientIp::network($ip), $api ? 86400 : 0);

        // Counted per pass when there is one (so guests sharing an office or
        // school address each get their own allowance), else per address
        // (per /64 for IPv6).
        $count = 0;

        if ($public && ($settings->burstBlock() > 0 || $settings->burstChallenge() > 0)) {
            $count = $this->counter->hit($passId !== null ? 'pass.'.$passId : ClientIp::bucket($ip));

            if ($settings->burstBlock() > 0 && $count > $settings->burstBlock()) {
                return new Verdict(Verdict::LIMIT, 'burst', $agent['name'] ?: null, true);
            }
        }

        $burst = $settings->burstChallenge() > 0 && $count > $settings->burstChallenge();

        // Allowed bots, link previews, monitors and feed readers can't solve
        // a challenge, and their user agents prove nothing: let in, but only
        // at a pace no real one needs, so borrowing the name buys little.
        if ($agent['type'] === 'bot' || $agent['type'] === 'friendly') {
            return $burst
                ? new Verdict(Verdict::CHALLENGE, 'burst', $agent['name'], true)
                : Verdict::pass($agent['type'], $agent['name'], $agent['type'] === 'bot');
        }

        if ($passId !== null) {
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

        if ($burst) {
            return new Verdict(Verdict::CHALLENGE, 'burst', $agent['name'] ?: null, true);
        }

        return Verdict::pass($unverified ? 'engine_unverified' : 'none');
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
