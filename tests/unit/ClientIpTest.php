<?php

namespace ErnestDefoe\Bouncer\Tests\unit;

use ErnestDefoe\Bouncer\ClientIp;
use ErnestDefoe\Bouncer\Network\Networks;
use Flarum\Foundation\Paths;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ClientIpTest extends TestCase
{
    protected function resolve(string $remote, array $headers = [], string $proxies = ''): string
    {
        $tmp = sys_get_temp_dir().'/bouncer-none';
        $networks = new Networks(new Paths(['base' => $tmp, 'public' => $tmp, 'storage' => $tmp, 'vendor' => $tmp]));
        $settings = TokensTest::settings(['ernestdefoe-bouncer.trusted_proxies' => $proxies]);

        return (new ClientIp($settings, $networks))->resolve(new ServerRequest(['REMOTE_ADDR' => $remote], [], '/', 'GET', 'php://memory', $headers));
    }

    #[Test]
    public function a_direct_visitor_cannot_forge_their_address()
    {
        $this->assertSame('198.51.100.9', $this->resolve('198.51.100.9', ['X-Forwarded-For' => '1.2.3.4']));
        $this->assertSame('198.51.100.9', $this->resolve('198.51.100.9', ['X-Real-IP' => '1.2.3.4']));
        $this->assertSame('198.51.100.9', $this->resolve('198.51.100.9', ['CF-Connecting-IP' => '1.2.3.4']));
    }

    #[Test]
    public function behind_a_local_proxy_the_last_untrusted_hop_counts()
    {
        // nginx in front of a container: REMOTE_ADDR is the Docker gateway.
        $this->assertSame('198.51.100.9', $this->resolve('172.18.0.1', ['X-Forwarded-For' => '198.51.100.9']));
        // Whatever the visitor prepends is ignored.
        $this->assertSame('198.51.100.9', $this->resolve('172.18.0.1', ['X-Forwarded-For' => '1.2.3.4, 198.51.100.9']));
        // Two proxies of our own.
        $this->assertSame('198.51.100.9', $this->resolve('127.0.0.1', ['X-Forwarded-For' => '6.6.6.6, 198.51.100.9, 10.0.0.2']));
        $this->assertSame('198.51.100.9', $this->resolve('127.0.0.1', ['X-Real-IP' => '198.51.100.9']));
        $this->assertSame('172.18.0.1', $this->resolve('172.18.0.1'), 'no headers: the proxy is all we know');
        $this->assertSame('172.18.0.1', $this->resolve('172.18.0.1', ['X-Forwarded-For' => 'garbage']));
    }

    #[Test]
    public function a_public_proxy_is_trusted_only_when_listed()
    {
        $this->assertSame('203.0.113.10', $this->resolve('203.0.113.10', ['X-Forwarded-For' => '198.51.100.9']));
        $this->assertSame('198.51.100.9', $this->resolve('203.0.113.10', ['X-Forwarded-For' => '198.51.100.9'], "203.0.113.0/24\n"));
    }

    #[Test]
    public function cf_connecting_ip_is_believed_only_from_cloudflare()
    {
        // Straight from Cloudflare.
        $this->assertSame('198.51.100.9', $this->resolve('173.245.48.10', ['CF-Connecting-IP' => '198.51.100.9']));
        // Cloudflare, then our nginx.
        $this->assertSame('198.51.100.9', $this->resolve('172.18.0.1', ['X-Forwarded-For' => '198.51.100.9, 173.245.48.10']));
        $this->assertSame('198.51.100.9', $this->resolve('172.18.0.1', ['X-Forwarded-For' => '173.245.48.10', 'CF-Connecting-IP' => '198.51.100.9']));
        // Not from Cloudflare: the header is ignored.
        $this->assertSame('203.0.113.50', $this->resolve('172.18.0.1', ['X-Forwarded-For' => '203.0.113.50', 'CF-Connecting-IP' => '198.51.100.9']));
    }

    #[Test]
    public function addresses_are_normalised()
    {
        $this->assertSame('2001:db8::1', $this->resolve('2001:0db8:0000::0001'));
        $this->assertSame('198.51.100.9', $this->resolve('::ffff:198.51.100.9'));
        $this->assertSame('198.51.100.9', $this->resolve('172.18.0.1', ['X-Forwarded-For' => '198.51.100.9:4711']));
        $this->assertSame('2001:db8::1', $this->resolve('172.18.0.1', ['X-Forwarded-For' => '[2001:db8::1]:443']));
    }

    #[Test]
    public function private_reserved_and_cgnat_addresses_are_not_public()
    {
        foreach (['10.1.1.1', '172.16.0.1', '192.168.1.1', '127.0.0.1', '100.64.0.1', '100.127.255.255', '169.254.1.1', '::1', 'fd00::1', '0.0.0.0'] as $ip) {
            $this->assertFalse(ClientIp::isPublic($ip), $ip);
        }

        $this->assertTrue(ClientIp::isPublic('100.128.0.1'));
        $this->assertTrue(ClientIp::isPublic('8.8.8.8'));
    }
}
