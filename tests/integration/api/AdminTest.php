<?php

namespace ErnestDefoe\Bouncer\Tests\integration\api;

use ErnestDefoe\Bouncer\Tests\integration\BouncerTestCase;
use PHPUnit\Framework\Attributes\Test;

class AdminTest extends BouncerTestCase
{
    protected function api(string $method, string $path, ?int $user, array $query = [])
    {
        $request = $this->request($method, $path, $user ? ['authenticatedAs' => $user] : [])->withQueryParams($query);

        return $this->send($request);
    }

    #[Test]
    public function only_admins_reach_the_staff_endpoints()
    {
        foreach ([['GET', '/api/bouncer/status'], ['GET', '/api/bouncer/log'], ['DELETE', '/api/bouncer/log'], ['POST', '/api/bouncer/networks'], ['GET', '/api/bouncer/robots-check']] as [$method, $path]) {
            $this->assertContains($this->api($method, $path, null)->getStatusCode(), [400, 401, 403], "guest $method $path (400: no CSRF token)");
            $this->assertSame(403, $this->api($method, $path, 2)->getStatusCode(), "member $method $path");
        }
    }

    #[Test]
    public function the_log_lists_filters_and_clears()
    {
        $this->get('/', ['User-Agent' => self::GPTBOT]);
        $this->get('/', ['User-Agent' => 'curl/8.4.0'], '198.51.100.50');

        $all = json_decode((string) $this->api('GET', '/api/bouncer/log', 1)->getBody(), true);
        $this->assertSame(2, $all['total']);
        $this->assertSame('curl/8.4.0', $all['rows'][0]['userAgent'], 'newest first');

        $blocked = json_decode((string) $this->api('GET', '/api/bouncer/log', 1, ['action' => 'blocked'])->getBody(), true);
        $this->assertSame(['GPTBot'], array_column($blocked['rows'], 'bot'));

        $search = json_decode((string) $this->api('GET', '/api/bouncer/log', 1, ['q' => '198.51.100'])->getBody(), true);
        $this->assertSame(1, $search['total']);

        $junk = json_decode((string) $this->api('GET', '/api/bouncer/log', 1, ['action' => "x' OR 1=1 --", 'q' => '%_%'])->getBody(), true);
        $this->assertSame(0, $junk['total']);

        $this->assertSame(204, $this->api('DELETE', '/api/bouncer/log', 1)->getStatusCode());
        $this->assertSame(0, $this->database()->table('bouncer_log')->count());

        // A bot still hammering after the clear starts a new row.
        $this->get('/', ['User-Agent' => self::GPTBOT]);
        $this->assertSame(1, $this->database()->table('bouncer_log')->count());
    }

    #[Test]
    public function the_status_shows_the_week_and_the_address_seen()
    {
        $this->get('/', ['User-Agent' => self::GPTBOT]);
        $this->get('/', ['User-Agent' => self::GPTBOT]);

        $status = json_decode((string) $this->api('GET', '/api/bouncer/status', 1)->getBody(), true);

        $this->assertCount(7, $status['days']);
        $this->assertSame(2, end($status['days'])['counts']['blocked']);
        $this->assertSame([['bot' => 'GPTBot', 'action' => 'blocked', 'reason' => 'bot', 'hits' => 2]], $status['bots']);
        $this->assertFalse($status['you']['public'], 'the test request comes from 127.0.0.1');
        $this->assertGreaterThan(5000, $status['networks']['ranges']);
        $this->assertSame('bundled', $status['networks']['source']);
        $this->assertStringContainsString('GPTBot', $status['robots']);
    }
}
