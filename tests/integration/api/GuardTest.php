<?php

namespace ErnestDefoe\Bouncer\Tests\integration\api;

use ErnestDefoe\Bouncer\Tests\integration\BouncerTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Who gets through, who gets a challenge, who gets turned away.
 */
class GuardTest extends BouncerTestCase
{
    #[Test]
    public function an_ordinary_guest_browses_without_noticing()
    {
        $this->assertSame(200, $this->get('/')->getStatusCode());
        $this->assertSame(200, $this->get('/api/discussions')->getStatusCode());
        $this->assertSame([], $this->logRows(), 'ordinary visitors are never logged');
    }

    #[Test]
    public function signed_in_members_are_never_checked()
    {
        $request = $this->requestAsUser($this->from('GET', '/', ['User-Agent' => 'curl/8.4.0', 'Accept-Language' => null]), 2);
        $this->assertSame(200, $this->send($request)->getStatusCode());

        $request = $this->requestAsUser($this->from('GET', '/api/discussions', ['User-Agent' => 'python-requests/2.31'], '3.5.140.2'), 2);
        $this->assertSame(200, $this->send($request)->getStatusCode());

        $this->set('everyone', '1');
        $this->assertSame(200, $this->send($this->requestAsUser($this->from('GET', '/'), 2))->getStatusCode());
    }

    #[Test]
    public function api_keys_and_tokens_are_never_checked()
    {
        $key = str_repeat('k', 40);
        $this->database()->table('api_keys')->insert(['key' => $key, 'user_id' => 1, 'created_at' => \Carbon\Carbon::now()]);

        $response = $this->send($this->from('GET', '/api/discussions', [
            'User-Agent' => 'curl/8.4.0',
            'Accept-Language' => null,
            'Authorization' => "Token $key; userId=1",
        ], '3.5.140.2'));

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function writes_are_never_checked()
    {
        // A login from a script: whatever Flarum says, it isn't Bouncer.
        $response = $this->send($this->from('POST', '/api/token', ['User-Agent' => 'okhttp/4.12', 'Content-Type' => 'application/json'])
            ->withParsedBody(['identification' => 'normal', 'password' => 'too-obscure']));

        $this->assertNotSame('bouncer_challenge_required', json_decode((string) $response->getBody(), true)['errors'][0]['code'] ?? null);
        $this->assertSame([], $this->logRows());
    }

    #[Test]
    public function blocked_ai_crawlers_are_turned_away_and_allowed_ones_let_in()
    {
        $response = $this->get('/', ['User-Agent' => self::GPTBOT]);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('robots.txt', (string) $response->getBody());

        $row = $this->logRows()[0];
        $this->assertSame(['blocked', 'bot', 'GPTBot', 1], [$row->action, $row->reason, $row->bot, (int) $row->hits]);

        $this->get('/d/1', ['User-Agent' => self::GPTBOT]);
        $this->assertSame(2, (int) $this->logRows()[0]->hits, 'one row, counted');
        $this->assertCount(1, $this->logRows());

        // Search and assistant bots are allowed by default.
        $this->assertSame(200, $this->get('/', ['User-Agent' => 'Mozilla/5.0 (compatible; OAI-SearchBot/1.0; +https://openai.com/searchbot)'])->getStatusCode());

        $this->set('bots', json_encode(['gptbot' => 'allow', 'oai-searchbot' => 'block']));
        $this->assertSame(200, $this->get('/', ['User-Agent' => self::GPTBOT])->getStatusCode());
        $this->assertSame(403, $this->get('/', ['User-Agent' => 'Mozilla/5.0 (compatible; OAI-SearchBot/1.0)'])->getStatusCode());
    }

    #[Test]
    public function a_pass_never_overrides_a_block()
    {
        $tokens = $this->app()->getContainer()->make(\ErnestDefoe\Bouncer\Tokens::class);

        $pass = $tokens->pass(self::GPTBOT, 3600);
        $this->assertSame(403, $this->get('/', ['User-Agent' => self::GPTBOT], self::IP, [$this->cookieName() => $pass])->getStatusCode());

        $this->set('block_ips', self::IP);
        $pass = $tokens->pass(self::CHROME, 3600);
        $this->assertSame(403, $this->get('/', [], self::IP, [$this->cookieName() => $pass])->getStatusCode());
    }

    #[Test]
    public function real_search_engines_pass_and_fakes_are_turned_away()
    {
        $this->dns('crawl-66-249-66-1.googlebot.com', ['66.249.66.1']);
        $response = $this->get('/', ['User-Agent' => self::GOOGLEBOT, 'Accept-Language' => null], '66.249.66.1');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['allowed', 'engine', 'Googlebot'], [$this->logRows()[0]->action, $this->logRows()[0]->reason, $this->logRows()[0]->bot]);

        // Reverse DNS says Google; forward DNS disagrees.
        $this->dns('crawl-66-249-66-2.googlebot.com', ['66.249.66.99']);
        $this->assertSame(403, $this->get('/', ['User-Agent' => self::GOOGLEBOT], '66.249.66.2')->getStatusCode());

        // A rented Google Cloud VM: resolves both ways, but not to googlebot.com.
        $this->dns('7.66.249.35.bc.googleusercontent.com', ['35.249.66.7']);
        $this->assertSame(403, $this->get('/', ['User-Agent' => self::GOOGLEBOT], '35.249.66.7')->getStatusCode());

        $this->assertSame('fake_engine', $this->logRows()[1]->reason);
    }

    #[Test]
    public function verified_search_engines_are_never_challenged_or_rate_limited()
    {
        $this->withinOneMinute();
        $this->dns('crawl-66-249-66-1.googlebot.com', ['66.249.66.1']);
        $this->set('everyone', '1');
        $this->set('burst_challenge', 2);
        $this->set('burst_block', 3);

        for ($i = 0; $i < 6; $i++) {
            $this->assertSame(200, $this->get('/', ['User-Agent' => self::GOOGLEBOT, 'Accept-Language' => null], '66.249.66.1')->getStatusCode());
        }
    }

    #[Test]
    public function a_fake_engine_cannot_get_past_by_using_up_the_dns_budget()
    {
        $this->withinOneMinute();
        // Over the lookup budget DNS answers "unknown": the bot may be real,
        // so it isn't blocked, but it stays rate limited.
        $cache = $this->app()->getContainer()->make('cache.store');
        $cache->put('ernestdefoe-bouncer.dns-budget.'.intdiv(time(), 60), 1000, 120);
        $this->set('burst_block', 2);

        $ua = ['User-Agent' => self::GOOGLEBOT];
        $this->assertSame(200, $this->get('/', $ua, '198.51.100.20')->getStatusCode());
        $this->assertSame(200, $this->get('/', $ua, '198.51.100.20')->getStatusCode());
        $this->assertSame(429, $this->get('/', $ua, '198.51.100.20')->getStatusCode());
    }

    #[Test]
    public function http_libraries_get_a_challenge_they_cannot_solve()
    {
        foreach (['curl/8.4.0', 'python-requests/2.31.0', 'Go-http-client/2.0', 'Scrapy/2.11 (+https://scrapy.org)'] as $ua) {
            $this->assertTrue($this->isChallenge($this->get('/', ['User-Agent' => $ua])), $ua);
        }

        $this->assertTrue($this->isChallenge($this->get('/', ['User-Agent' => null])), 'no user agent');

        $this->set('tools', 'block');
        $response = $this->get('/', ['User-Agent' => 'curl/8.4.0']);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($this->isChallenge($response));

        $this->set('tools', 'off');
        $this->assertSame(200, $this->get('/', ['User-Agent' => 'curl/8.4.0'])->getStatusCode());
    }

    #[Test]
    public function a_browser_without_browser_headers_is_challenged()
    {
        $this->assertTrue($this->isChallenge($this->get('/', ['Accept-Language' => null])));
        $this->assertSame(200, $this->get('/', ['Sec-Fetch-Mode' => null])->getStatusCode(), 'not held to Sec-Fetch over plain HTTP');

        $this->set('headers', 'off');
        $this->assertSame(200, $this->get('/', ['Accept-Language' => null])->getStatusCode());
    }

    #[Test]
    public function over_https_a_modern_chrome_must_send_fetch_metadata()
    {
        $this->config('url', 'https://localhost');
        $this->assertTrue($this->isChallenge($this->get('/', ['Sec-Fetch-Mode' => null])));
        $this->assertSame(200, $this->get('/')->getStatusCode());

        // Safari's user agent isn't held to it.
        $safari = 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1';
        $this->assertSame(200, $this->get('/', ['User-Agent' => $safari, 'Sec-Fetch-Mode' => null])->getStatusCode());
    }

    #[Test]
    public function data_centre_visitors_are_challenged_and_others_are_not()
    {
        // An AWS address.
        $this->assertTrue($this->isChallenge($this->get('/', [], '3.5.140.2')));
        $this->assertSame('datacentre', $this->logRows()[0]->reason);

        $this->assertSame(200, $this->get('/', [], '8.8.8.8')->getStatusCode());

        $this->set('datacentre', 'off');
        $this->assertSame(200, $this->get('/', [], '3.5.140.2')->getStatusCode());
    }

    #[Test]
    public function friendly_fetchers_pass_unless_they_burst()
    {
        $this->withinOneMinute();
        $this->set('burst_challenge', 2);
        $ua = ['User-Agent' => 'Mozilla/5.0 (compatible; Discordbot/2.0; +https://discordapp.com)', 'Accept-Language' => null];

        $this->assertSame(200, $this->get('/', $ua, '3.5.140.2')->getStatusCode(), 'even from a data centre');
        $this->assertSame(200, $this->get('/', $ua, '3.5.140.2')->getStatusCode());
        $this->assertTrue($this->isChallenge($this->get('/', $ua, '3.5.140.2')));
    }

    #[Test]
    public function bursts_are_challenged_then_refused()
    {
        $this->withinOneMinute();
        $this->set('burst_challenge', 3);
        $this->set('burst_block', 5);

        for ($i = 1; $i <= 3; $i++) {
            $this->assertSame(200, $this->get('/api/discussions')->getStatusCode(), "request $i");
        }

        $this->assertSame(403, $this->get('/api/discussions')->getStatusCode());
        $this->assertTrue($this->isChallenge($this->get('/')));

        $response = $this->get('/');
        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame('60', $response->getHeaderLine('Retry-After'));

        // Another address is unaffected.
        $this->assertSame(200, $this->get('/', [], '198.51.100.30')->getStatusCode());
    }

    #[Test]
    public function rate_limits_are_skipped_when_every_visitor_looks_the_same()
    {
        // A reverse proxy that doesn't pass addresses on: everyone is
        // 172.18.0.1, and counting them together would challenge everyone.
        $this->set('burst_challenge', 1);
        $this->set('burst_block', 2);

        for ($i = 0; $i < 4; $i++) {
            $this->assertSame(200, $this->get('/', [], '172.18.0.1')->getStatusCode());
        }
    }

    #[Test]
    public function under_attack_every_guest_is_challenged()
    {
        $this->set('everyone', '1');

        $this->assertTrue($this->isChallenge($this->get('/')));
        $this->assertSame(['challenged', 'everyone'], [$this->logRows()[0]->action, $this->logRows()[0]->reason]);
    }

    #[Test]
    public function monitor_mode_only_logs()
    {
        $this->set('mode', 'monitor');

        $this->assertSame(200, $this->get('/', ['User-Agent' => 'curl/8.4.0'])->getStatusCode());
        $this->assertSame(200, $this->get('/', ['User-Agent' => self::GPTBOT])->getStatusCode());

        $this->assertSame(['would_challenge', 'would_block'], array_map(fn ($r) => $r->action, $this->logRows()));
    }

    #[Test]
    public function staff_lists_come_first()
    {
        $this->set('allow_ips', "198.51.100.0/24 # office\n");
        $this->set('block_ips', "192.0.2.66\n2001:db8:bad::/48");
        $this->set('allow_agents', 'MyUptimeChecker');
        $this->set('block_agents', 'NastyBot');

        $this->assertSame(200, $this->get('/', ['User-Agent' => 'curl/8'], '198.51.100.7')->getStatusCode());
        $this->assertSame(200, $this->get('/', ['User-Agent' => self::GPTBOT], '198.51.100.7')->getStatusCode());
        $this->assertSame(403, $this->get('/', [], '192.0.2.66')->getStatusCode());
        $this->assertSame(403, $this->get('/', [], '2001:db8:bad::1')->getStatusCode());
        $this->assertSame(200, $this->get('/', ['User-Agent' => 'curl/8 myuptimechecker'])->getStatusCode());
        $this->assertSame(403, $this->get('/', ['User-Agent' => self::CHROME.' NastyBot/1'])->getStatusCode());
    }

    #[Test]
    public function exempt_paths_and_guarding_the_api_are_up_to_the_admin()
    {
        $this->assertSame(200, $this->get('/robots.txt', ['User-Agent' => self::GPTBOT])->getStatusCode(), 'a blocked bot can read robots.txt');

        $this->set('exempt_paths', '/d/');
        $this->assertSame(404, $this->get('/d/999', ['User-Agent' => 'curl/8'])->getStatusCode());

        $response = $this->get('/api/discussions', ['User-Agent' => 'curl/8']);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('bouncer_challenge_required', json_decode((string) $response->getBody(), true)['errors'][0]['code']);

        $this->set('api', '0');
        $this->assertSame(200, $this->get('/api/discussions', ['User-Agent' => 'curl/8'])->getStatusCode());
    }
}
