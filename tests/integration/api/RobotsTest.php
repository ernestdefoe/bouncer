<?php

namespace ErnestDefoe\Bouncer\Tests\integration\api;

use ErnestDefoe\Bouncer\Robots;
use ErnestDefoe\Bouncer\Tests\integration\BouncerTestCase;
use PHPUnit\Framework\Attributes\Test;

class RobotsTest extends BouncerTestCase
{
    #[Test]
    public function robots_txt_lists_the_blocked_bots()
    {
        $response = $this->get('/robots.txt', ['User-Agent' => self::GPTBOT]);
        $body = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('robots', $response->getHeaderLine('X-Bouncer'));
        $this->assertStringStartsWith("User-agent: *\nDisallow:\n", $body);
        $this->assertStringContainsString(Robots::MARKER, $body);
        $this->assertStringContainsString("User-agent: GPTBot\n", $body);
        $this->assertStringContainsString("User-agent: Google-Extended\n", $body);
        $this->assertStringNotContainsString('OAI-SearchBot', $body, 'allowed bots are not listed');
        $this->assertStringEndsWith("Disallow: /\n", $body);
    }

    #[Test]
    public function robots_txt_follows_the_admins_choices()
    {
        $this->set('bots', json_encode(['gptbot' => 'allow', 'perplexitybot' => 'block']));
        $body = (string) $this->get('/robots.txt')->getBody();

        $this->assertStringNotContainsString('GPTBot', $body);
        $this->assertStringContainsString('User-agent: PerplexityBot', $body);

        $this->set('robots', '0');
        $this->assertSame(404, $this->get('/robots.txt')->getStatusCode(), 'left to the rest of the forum');
    }

    #[Test]
    public function ai_txt_is_served_only_when_turned_on()
    {
        $this->assertSame(404, $this->get('/ai.txt')->getStatusCode());

        $this->set('ai_txt', '1');
        $response = $this->get('/ai.txt');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString("User-Agent: *\nDisallow: /", (string) $response->getBody());
    }

    #[Test]
    public function another_extensions_robots_txt_is_kept_and_added_to()
    {
        $robots = $this->app()->getContainer()->make(Robots::class);
        $body = $robots->robotsTxt("User-agent: *\nDisallow: /admin\nSitemap: https://x/sitemap.xml");

        $this->assertStringStartsWith("User-agent: *\nDisallow: /admin\nSitemap: https://x/sitemap.xml\n\n".Robots::MARKER, $body);
        $this->assertSame($body, $robots->robotsTxt($body), 'never added twice');
    }
}
