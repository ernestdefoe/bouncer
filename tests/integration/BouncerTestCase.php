<?php

namespace ErnestDefoe\Bouncer\Tests\integration;

use ErnestDefoe\Bouncer\Crawler;
use ErnestDefoe\Bouncer\Http\Guard;
use ErnestDefoe\Bouncer\Tokens;
use Flarum\Http\CookieFactory;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Laminas\Diactoros\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

abstract class BouncerTestCase extends TestCase
{
    use RetrievesAuthorizedUsers;

    public const P = 'ernestdefoe-bouncer.';

    public const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';

    public const GOOGLEBOT = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

    public const GPTBOT = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.2; +https://openai.com/gptbot';

    /** A residential-looking public address (documentation range, not a data centre). */
    public const IP = '203.0.113.5';

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-bouncer');
        $this->prepareDatabase([
            'users' => [$this->normalUser()],
        ]);
        // Fast challenges in tests.
        $this->setting(self::P.'difficulty', 8);
    }

    /** Change a Bouncer setting, before or after the app has booted. */
    protected function set(string $key, mixed $value): void
    {
        $this->app()->getContainer()->make(SettingsRepositoryInterface::class)->set(self::P.$key, $value);
    }

    /** The headers a real browser sends with a page load. */
    protected function browserHeaders(): array
    {
        return [
            'User-Agent' => self::CHROME,
            'Accept' => 'text/html,application/xhtml+xml',
            'Accept-Language' => 'en-GB,en;q=0.9',
            'Sec-Fetch-Mode' => 'navigate',
        ];
    }

    /**
     * A request from an address, with a browser's headers unless replaced
     * (a header set to null is left out).
     */
    protected function from(string $method, string $path, array $headers = [], string $ip = self::IP, array $cookies = []): ServerRequestInterface
    {
        $headers = array_filter(array_merge($this->browserHeaders(), $headers), fn ($v) => $v !== null);
        $request = new ServerRequest(['REMOTE_ADDR' => $ip], [], $path, $method, 'php://memory', $headers, $cookies);

        return $request;
    }

    protected function get(string $path, array $headers = [], string $ip = self::IP, array $cookies = []): ResponseInterface
    {
        return $this->send($this->from('GET', $path, $headers, $ip, $cookies));
    }

    protected function cookieName(): string
    {
        return $this->app()->getContainer()->make(CookieFactory::class)->getName(Guard::COOKIE);
    }

    /** Extracts the challenge token from a challenge page. */
    protected function token(ResponseInterface $response): string
    {
        $this->assertSame(403, $response->getStatusCode());
        $this->assertTrue(preg_match('/name="c" value="([^"]+)"/', (string) $response->getBody(), $m) === 1, 'a challenge page');

        return html_entity_decode($m[1]);
    }

    protected function solve(string $token): string
    {
        $difficulty = (int) explode('.', $token)[1];
        $n = 0;

        while (! Tokens::solves($token, (string) $n, $difficulty)) {
            $n++;
        }

        return (string) $n;
    }

    protected function verify(string $token, ?string $solution, array $headers = [], string $ip = self::IP): ResponseInterface
    {
        return $this->send($this->from('POST', '/bouncer/verify', $headers, $ip)->withParsedBody(['c' => $token, 's' => $solution ?? $this->solve($token)]));
    }

    /** The pass cookie a response set, if any. */
    protected function passFrom(ResponseInterface $response): ?string
    {
        foreach ($response->getHeader('Set-Cookie') as $cookie) {
            if (str_starts_with($cookie, $this->cookieName().'=')) {
                return urldecode(explode(';', substr($cookie, strlen($this->cookieName()) + 1))[0]);
            }
        }

        return null;
    }

    protected function dns(string $host, array $forward): void
    {
        $this->app()->getContainer()->make(Crawler::class)->resolveWith(
            fn (string $ip) => $host,
            fn (string $name) => $name === $host ? $forward : [],
        );
    }

    /** @return list<object> */
    protected function logRows(): array
    {
        return $this->database()->table('bouncer_log')->orderBy('id')->get()->all();
    }

    protected function isChallenge(ResponseInterface $response): bool
    {
        return $response->getStatusCode() === 403 && str_contains((string) $response->getBody(), 'name="c"');
    }
}
