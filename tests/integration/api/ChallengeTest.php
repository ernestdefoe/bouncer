<?php

namespace ErnestDefoe\Bouncer\Tests\integration\api;

use ErnestDefoe\Bouncer\Tests\integration\BouncerTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The challenge round trip: page, answer, pass, back to the page.
 */
class ChallengeTest extends BouncerTestCase
{
    protected function challenged(string $path = '/d/1?near=3'): string
    {
        $this->set('everyone', '1');

        return $this->token($this->get($path));
    }

    #[Test]
    public function solving_the_challenge_earns_a_pass_and_returns_to_the_page()
    {
        $this->assertSame(200, $this->get('/?sort=newest')->getStatusCode(), 'no challenge yet');

        $this->set('everyone', '1');
        $challenge = $this->get('/?sort=newest');
        $this->assertTrue($this->isChallenge($challenge));
        $this->assertSame('no-store, private', $challenge->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString('noindex', $challenge->getHeaderLine('X-Robots-Tag'));
        $this->assertStringNotContainsString('forum.js', (string) $challenge->getBody(), 'no forum assets');

        $answer = $this->verify($this->token($challenge), null);
        $this->assertSame(303, $answer->getStatusCode());
        $this->assertSame('http://localhost/?sort=newest', $answer->getHeaderLine('Location'));

        $pass = $this->passFrom($answer);
        $this->assertNotNull($pass);
        $this->assertStringContainsString('HttpOnly', implode(';', $answer->getHeader('Set-Cookie')));

        $this->assertSame(200, $this->get('/', [], self::IP, [$this->cookieName() => $pass])->getStatusCode());
        $this->assertSame(200, $this->get('/api/discussions', [], '198.51.100.40', [$this->cookieName() => $pass])->getStatusCode(), 'the pass travels with the browser, not the address');

        $actions = array_map(fn ($r) => $r->action, $this->logRows());
        $this->assertContains('challenged', $actions);
        $this->assertContains('solved', $actions);
    }

    #[Test]
    public function a_pass_belongs_to_one_browser()
    {
        $pass = $this->passFrom($this->verify($this->challenged(), null));

        $this->assertTrue($this->isChallenge($this->get('/', ['User-Agent' => self::CHROME.' Edg/130.0'], self::IP, [$this->cookieName() => $pass])));
        $this->assertTrue($this->isChallenge($this->get('/', [], self::IP, [$this->cookieName() => $pass.'x'])));
        $this->assertTrue($this->isChallenge($this->get('/', [], self::IP, [$this->cookieName() => '9999999999.'.str_repeat('a', 32)])));
    }

    #[Test]
    public function a_wrong_answer_gets_a_fresh_challenge()
    {
        $token = $this->challenged();
        $solution = (int) $this->solve($token);

        // An answer that doesn't solve it.
        $wrong = $solution + 1;
        while (\ErnestDefoe\Bouncer\Tokens::solves($token, (string) $wrong, 8)) {
            $wrong++;
        }

        $response = $this->verify($token, (string) $wrong);
        $this->assertNotSame($token, $this->token($response));
        $this->assertNull($this->passFrom($response));
        $rows = $this->logRows();
        $this->assertSame(['failed', 'wrong'], [end($rows)->action, end($rows)->reason]);
    }

    #[Test]
    public function a_challenge_works_once_for_the_browser_and_address_it_was_given_to()
    {
        $token = $this->challenged();
        $solution = $this->solve($token);

        $this->assertNull($this->passFrom($this->verify($token, $solution, ['User-Agent' => 'curl/8'])), 'another user agent');
        $this->assertNull($this->passFrom($this->verify($token, $solution, [], '198.51.100.41')), 'another address');

        $this->assertNotNull($this->passFrom($this->verify($token, $solution)));
        $this->assertNull($this->passFrom($this->verify($token, $solution)), 'used once');
    }

    #[Test]
    public function the_difficulty_cannot_be_lowered()
    {
        $this->set('difficulty', 12);
        $token = $this->challenged();
        $parts = explode('.', $token);
        $parts[1] = '1';
        $easy = implode('.', $parts);

        $this->assertNull($this->passFrom($this->verify($easy, $this->solve($easy))));
    }

    #[Test]
    public function the_return_path_always_stays_on_the_forum()
    {
        $tokens = $this->app()->getContainer()->make(\ErnestDefoe\Bouncer\Tokens::class);

        foreach (['//evil.example/x', '/\\evil.example/x', "/\t/evil.example", 'https://evil.example/'] as $path) {
            $token = $tokens->challenge(self::IP, self::CHROME, $path, 8);
            $location = $this->verify($token, null)->getHeaderLine('Location');
            $this->assertSame('localhost', parse_url($location, PHP_URL_HOST), $path);
            $this->assertStringStartsWith('http://localhost/', $location, $path);
        }
    }

    #[Test]
    public function verify_attempts_are_capped_per_address()
    {
        $token = $this->challenged();

        for ($i = 0; $i < 20; $i++) {
            $this->verify($token, 'x');
        }

        $this->assertNull($this->passFrom($this->verify($token, null)), 'even a right answer, over the cap');
        $this->assertNotNull($this->passFrom($this->verify($this->token($this->get('/', [], '198.51.100.42')), null, [], '198.51.100.42')));
    }

    #[Test]
    public function the_api_tells_scripts_to_use_a_browser()
    {
        $response = $this->get('/api/discussions', ['User-Agent' => 'curl/8.4.0']);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('application/vnd.api+json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('bouncer_challenge_required', json_decode((string) $response->getBody(), true)['errors'][0]['code']);
    }

    #[Test]
    public function a_page_left_open_keeps_loading_for_a_day_after_its_pass_expires()
    {
        $tokens = $this->app()->getContainer()->make(\ErnestDefoe\Bouncer\Tokens::class);
        $expired = $tokens->pass(self::CHROME, -3600);
        $this->set('everyone', '1');

        $this->assertSame(200, $this->get('/api/discussions', [], self::IP, [$this->cookieName() => $expired])->getStatusCode());
        $this->assertTrue($this->isChallenge($this->get('/', [], self::IP, [$this->cookieName() => $expired])), 'a new page load re-checks');
    }

    #[Test]
    public function turnstile_is_used_when_chosen_and_keys_exist()
    {
        $this->set('challenge', 'turnstile');
        $this->set('everyone', '1');

        // No Turnstile keys: falls back to proof of work.
        $this->assertStringNotContainsString('data-sitekey', (string) $this->get('/')->getBody());

        $settings = $this->app()->getContainer()->make(\Flarum\Settings\SettingsRepositoryInterface::class);
        $settings->set('flectar-turnstile.site_key', 'site-key');
        $settings->set('flectar-turnstile.secret_key', 'secret-key');

        $page = $this->get('/');
        $this->assertStringContainsString('data-sitekey="site-key"', (string) $page->getBody());
        $this->assertStringNotContainsString('secret-key', (string) $page->getBody());
        $token = $this->token($page);

        $controller = $this->app()->getContainer()->make(\ErnestDefoe\Bouncer\Http\VerifyController::class);
        $this->app()->getContainer()->instance(\ErnestDefoe\Bouncer\Http\VerifyController::class, $controller);
        $seen = [];
        $controller->turnstileWith(function ($secret, $response, $ip) use (&$seen) {
            $seen[] = [$secret, $response, $ip];

            return $response === 'good';
        });

        $post = fn (array $body) => $this->send($this->from('POST', '/bouncer/verify')->withParsedBody($body));

        $this->assertNull($this->passFrom($post(['c' => $token, 's' => $this->solve($token)])), 'a proof of work is no answer to a Turnstile challenge');
        $this->assertNull($this->passFrom($post(['c' => $token, 'cf-turnstile-response' => 'bad'])));
        $this->assertNotNull($this->passFrom($post(['c' => $token, 'cf-turnstile-response' => 'good'])));
        $this->assertSame(['secret-key', 'good', self::IP], end($seen));
    }
}
