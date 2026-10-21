<?php

namespace ErnestDefoe\Bouncer\Tests\unit;

use ErnestDefoe\Bouncer\Agents;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AgentsTest extends TestCase
{
    #[Test]
    public function it_tells_engines_bots_friends_tools_and_browsers_apart()
    {
        $cases = [
            'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' => ['engine', 'Googlebot'],
            'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm) Chrome/116.0' => ['engine', 'Bingbot'],
            'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.2; +https://openai.com/gptbot' => ['bot', 'GPTBot'],
            'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ClaudeBot/1.0; +claudebot@anthropic.com)' => ['bot', 'ClaudeBot'],
            'Mozilla/5.0 (compatible; OAI-SearchBot/1.0; +https://openai.com/searchbot)' => ['bot', 'OAI-SearchBot'],
            'Mozilla/5.0 (Linux; Android 5.0) AppleWebKit/537.36 (KHTML, like Gecko) Mobile Safari/537.36 (compatible; Bytespider; spider-feedback@bytedance.com)' => ['bot', 'Bytespider'],
            'http.rb/5.1.1 (Mastodon/4.2.0; +https://mastodon.social/)' => ['friendly', 'mastodon'],
            'Mozilla/5.0 (compatible; Discordbot/2.0; +https://discordapp.com)' => ['friendly', 'discordbot'],
            'Mozilla/5.0+(compatible; UptimeRobot/2.0; http://www.uptimerobot.com/)' => ['friendly', 'uptimerobot'],
            'curl/8.4.0' => ['tool', 'curl'],
            'facebookexternalhit/1.1 python-requests/2.31' => ['tool', 'python-requests'],
            'python-requests/2.31.0' => ['tool', 'python-requests'],
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/120.0.0.0 Safari/537.36' => ['tool', 'headlesschrome'],
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36' => ['browser', ''],
            'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1' => ['browser', ''],
            '' => ['empty', ''],
        ];

        foreach ($cases as $ua => [$type, $name]) {
            $agent = Agents::identify($ua);
            $this->assertSame($type, $agent['type'], $ua);
            $this->assertSame($name, $agent['name'], $ua);
        }
    }

    #[Test]
    public function google_user_content_hosts_are_not_googlebot()
    {
        // Anyone can rent a Google Cloud VM whose reverse DNS ends in
        // googleusercontent.com, so that domain never verifies Googlebot.
        foreach (Agents::ENGINES as [$name, $suffixes]) {
            $this->assertNotContains('.googleusercontent.com', $suffixes, $name);
        }
    }

    #[Test]
    public function only_browsers_that_always_send_fetch_metadata_are_held_to_it()
    {
        $this->assertTrue(Agents::claimsFetchMetadata('Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36'));
        $this->assertTrue(Agents::claimsFetchMetadata('Mozilla/5.0 (X11; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0'));
        $this->assertFalse(Agents::claimsFetchMetadata('Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/49.0.2623.112 Safari/537.36'));
        $this->assertFalse(Agents::claimsFetchMetadata('Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/120.0 Mobile/15E148 Safari/604.1'));
        $this->assertFalse(Agents::claimsFetchMetadata('Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1'));
    }

    #[Test]
    public function every_listed_bot_is_well_formed()
    {
        foreach (Agents::BOTS as $key => $bot) {
            $this->assertCount(6, $bot, $key);
            $this->assertContains($bot[2], ['training', 'search', 'assistant', 'seo'], $key);
            $this->assertNotEmpty($bot[5], "$key has robots.txt tokens");

            foreach ($bot[4] as $token) {
                $this->assertSame(strtolower($token), $token, "$key token is lowercase");
            }
        }
    }
}
