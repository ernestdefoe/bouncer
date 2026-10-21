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

        $pass = $tokens->pass(self::GPTBOT, \ErnestDefoe\Bouncer\ClientIp::network(self::IP), 3600);
        $this->assertSame(403, $this->get('/', ['User-Agent' => self::GPTBOT], self::IP, [$this->cookieName() => $pass])->getStatusCode());

        $this->set('block_ips', self::IP);
        $pass = $tokens->pass(self::CHROME, \ErnestDefoe\Bouncer\ClientIp::network(self::IP), 3600);
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
    public function the_server_checking_itself_is_never_challenged()
    {
        // A Docker health check: curl http://127.0.0.1/ from inside the container.
        $this->set('everyone', '1');

        $this->assertSame(200, $this->get('/', ['User-Agent' => 'curl/8.4.0', 'Accept-Language' => null], '127.0.0.1')->getStatusCode());
        $this->assertSame(200, $this->get('/', ['User-Agent' => 'curl/8.4.0'], '::1')->getStatusCode());
        $this->assertSame([], $this->logRows());

        // Behind a proxy, a loopback hop a visitor writes into the header is skipped.
        $this->set('ip_header', 'x-forwarded-for');
        $this->assertTrue($this->isChallenge($this->get('/', ['User-Agent' => 'curl/8', 'X-Forwarded-For' => '127.0.0.1, 198.51.100.60'], '172.18.0.1')));

        // A private address that isn't loopback (a proxy that hides visitors) is still checked.
        $this->assertTrue($this->isChallenge($this->get('/', ['User-Agent' => 'curl/8'], '172.18.0.1')));
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

    #[Test]
    public function the_proxy_header_is_read_only_when_chosen()
    {
        $headers = ['User-Agent' => self::GOOGLEBOT, 'X-Forwarded-For' => '66.249.66.1', 'Accept-Language' => null];
        $this->dns('crawl-66-249-66-1.googlebot.com', ['66.249.66.1']);

        // Default: the header is ignored, the request is the proxy's (private) address, so no DNS
        // verification happens and Googlebot's name earns nothing.
        $this->assertSame([], array_filter($this->logRows(), fn ($r) => $r->reason === 'engine'));
        $this->get('/', $headers, '172.18.0.1');
        $this->assertSame([], array_values(array_filter($this->logRows(), fn ($r) => $r->reason === 'engine')));

        $this->set('ip_header', 'x-real-ip');
        $this->get('/', $headers, '172.18.0.1');
        $this->assertSame([], array_values(array_filter($this->logRows(), fn ($r) => $r->reason === 'engine')), 'X-Forwarded-For is not read when X-Real-IP is chosen');

        $this->set('ip_header', 'x-forwarded-for');
        $this->assertSame(200, $this->get('/', $headers, '172.18.0.1')->getStatusCode());
        $this->assertSame('66.249.66.1', array_values(array_filter($this->logRows(), fn ($r) => $r->reason === 'engine'))[0]->ip);
    }

    #[Test]
    public function an_unverified_engine_still_faces_the_network_and_burst_checks()
    {
        $cache = $this->app()->getContainer()->make('cache.store');
        $this->withinOneMinute();
        $cache->put('ernestdefoe-bouncer.dns-budget.'.intdiv(time(), 60), 1000, 120);

        $this->assertTrue($this->isChallenge($this->get('/', ['User-Agent' => self::GOOGLEBOT], '3.5.140.2')), 'a "Googlebot" in AWS');
        $this->assertSame(200, $this->get('/', ['User-Agent' => self::GOOGLEBOT, 'Accept-Language' => null], '66.249.66.5')->getStatusCode(), 'one in Google\'s own network');
    }

    #[Test]
    public function a_dns_error_is_never_taken_for_a_fake()
    {
        $lookups = 0;
        $this->app()->getContainer()->make(\ErnestDefoe\Bouncer\Crawler::class)->resolveWith(
            function () use (&$lookups) {
                $lookups++;

                return null;
            },
            fn () => [],
        );

        $this->assertSame(200, $this->get('/', ['User-Agent' => self::GOOGLEBOT, 'Accept-Language' => null], '66.249.66.6')->getStatusCode());
        $this->get('/', ['User-Agent' => self::GOOGLEBOT, 'Accept-Language' => null], '66.249.66.6');
        $this->assertSame(2, $lookups, 'not cached: asked again next time');
    }

    #[Test]
    public function a_name_outside_the_engines_domain_is_never_looked_up_forwards()
    {
        $forward = 0;
        $this->app()->getContainer()->make(\ErnestDefoe\Bouncer\Crawler::class)->resolveWith(
            fn () => 'slow.attacker.example',
            function () use (&$forward) {
                $forward++;

                return [];
            },
        );

        $this->assertSame(403, $this->get('/', ['User-Agent' => self::GOOGLEBOT], '198.51.100.70')->getStatusCode());
        $this->assertSame(0, $forward);
    }

    #[Test]
    public function borrowed_names_buy_only_a_slow_pace()
    {
        $this->withinOneMinute();
        $this->set('burst_challenge', 2);
        $ua = ['User-Agent' => 'Mozilla/5.0 (compatible; ChatGPT-User/1.0; +https://openai.com/bot)', 'Accept-Language' => null];

        $this->assertSame(200, $this->get('/', $ua, '3.5.140.9')->getStatusCode());
        $this->assertSame(200, $this->get('/', $ua, '3.5.140.9')->getStatusCode());
        $this->assertTrue($this->isChallenge($this->get('/', $ua, '3.5.140.9')));

        $this->assertTrue($this->isChallenge($this->get('/', ['User-Agent' => 'facebookexternalhit/1.1 python-requests/2.31'])), 'a library in a friendly name is a library');
    }

    #[Test]
    public function ipv6_addresses_in_one_64_share_a_count()
    {
        $this->withinOneMinute();
        $this->set('burst_block', 3);

        foreach (['2001:db8:5:6::1', '2001:db8:5:6::2', '2001:db8:5:6:a::3'] as $ip) {
            $this->assertSame(200, $this->get('/', [], $ip)->getStatusCode());
        }

        $this->assertSame(429, $this->get('/', [], '2001:db8:5:6:ffff::9')->getStatusCode());
        $this->assertSame(200, $this->get('/', [], '2001:db8:5:7::1')->getStatusCode(), 'the next /64 is someone else');
    }

    #[Test]
    public function guests_sharing_an_address_are_counted_by_their_passes()
    {
        $this->withinOneMinute();
        $this->set('burst_block', 2);
        $tokens = $this->app()->getContainer()->make(\ErnestDefoe\Bouncer\Tokens::class);
        $net = \ErnestDefoe\Bouncer\ClientIp::network(self::IP);
        $alice = [$this->cookieName() => $tokens->pass(self::CHROME, $net, 3600)];
        $bob = [$this->cookieName() => $tokens->pass(self::CHROME.' Edg/130', $net, 3600)];

        foreach ([[[], $alice], [['User-Agent' => self::CHROME.' Edg/130'], $bob]] as [$headers, $cookie]) {
            $this->assertSame(200, $this->get('/', $headers, self::IP, $cookie)->getStatusCode());
            $this->assertSame(200, $this->get('/', $headers, self::IP, $cookie)->getStatusCode());
        }

        $this->assertSame(429, $this->get('/', [], self::IP, $alice)->getStatusCode(), 'each pass has its own limit');
    }

    #[Test]
    public function an_api_key_without_a_user_is_never_checked()
    {
        $key = str_repeat('u', 40);
        $this->database()->table('api_keys')->insert(['key' => $key, 'user_id' => null, 'created_at' => \Carbon\Carbon::now()]);

        $response = $this->get('/api/discussions', ['User-Agent' => 'GuzzleHttp/7', 'Authorization' => "Token $key"], '3.5.140.2');
        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function the_pwa_manifest_and_service_worker_are_never_checked()
    {
        $this->set('everyone', '1');

        foreach (['/webmanifest', '/sw', '/offline'] as $path) {
            $this->assertFalse($this->isChallenge($this->get($path)), $path);
        }
    }
}
