<?php

/*
 * This file is part of ernestdefoe/bouncer.
 */

namespace ErnestDefoe\Bouncer\Http;

use ErnestDefoe\Bouncer\ClientIp;
use ErnestDefoe\Bouncer\Log;
use ErnestDefoe\Bouncer\Settings;
use ErnestDefoe\Bouncer\Tokens;
use Flarum\Http\CookieFactory;
use Flarum\Http\UrlGenerator;
use GuzzleHttp\Client;
use Illuminate\Contracts\Cache\Repository as Cache;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Checks a challenge's answer. The challenge is signed (so its difficulty,
 * return path, address and user agent can't be changed), ten minutes old at
 * most, and used once. A right answer earns the pass cookie and a redirect
 * back; a wrong one, a fresh challenge.
 *
 * Exempt from CSRF on purpose: a guest has no session token to send, and
 * the worst a forged post can do is give someone a pass they'd have earned
 * by loading the page.
 */
class VerifyController implements RequestHandlerInterface
{
    public const TURNSTILE_VERIFY = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public const TRIES_PER_MINUTE = 20;

    /** @var \Closure(string, string, string): bool */
    protected \Closure $turnstileCheck;

    public function __construct(
        protected Settings $settings,
        protected Tokens $tokens,
        protected ClientIp $clientIp,
        protected Challenge $challenge,
        protected CookieFactory $cookies,
        protected UrlGenerator $url,
        protected Cache $cache,
        protected Log $log,
    ) {
        $this->turnstileCheck = $this->askTurnstile(...);
    }

    /**
     * Replace the Turnstile check (tests).
     *
     * @param \Closure(string, string, string): bool $check secret, response, ip
     */
    public function turnstileWith(\Closure $check): void
    {
        $this->turnstileCheck = $check;
    }

    public function handle(Request $request): Response
    {
        $body = (array) $request->getParsedBody();
        $token = is_string($body['c'] ?? null) ? $body['c'] : '';
        $ip = $this->clientIp->resolve($request);
        $ua = $request->getHeaderLine('User-Agent');
        $data = $this->tokens->readChallenge($token, ClientIp::network($ip), $ua);

        // Twenty tries a minute per address: answers are cheap to check, but
        // a Turnstile answer costs a call to Cloudflare.
        $tries = 'ernestdefoe-bouncer.verify.'.intdiv(time(), 60).'.'.md5(ClientIp::bucket($ip));
        $this->cache->add($tries, 0, 120);

        if ((int) $this->cache->increment($tries) > self::TRIES_PER_MINUTE) {
            $data = null;
        }

        if ($data !== null && $this->solved($data, $body, $ip) && $this->cache->add('ernestdefoe-bouncer.used.'.$data['nonce'], 1, Tokens::CHALLENGE_TTL + 60)) {
            $this->log->record($ip, $ua, $data['return'], 'solved', $data['difficulty'] === 0 ? 'turnstile' : 'pow');

            $pass = $this->tokens->pass($ua, ClientIp::network($ip), $this->settings->passLifetime());
            $cookie = $this->cookies->make(Guard::COOKIE, $pass, $this->settings->passLifetime());

            return (new RedirectResponse($this->back($data['return']), 303))
                ->withAddedHeader('Set-Cookie', $cookie->__toString())
                ->withHeader('Cache-Control', 'no-store');
        }

        $this->log->record($ip, $ua, '/bouncer/verify', 'failed', $data === null ? 'invalid' : 'wrong');

        return $this->challenge->page($request, $ip, $data['return'] ?? '/', true);
    }

    /**
     * @param array{difficulty: int} $data
     * @param array<mixed> $body
     */
    protected function solved(array $data, array $body, string $ip): bool
    {
        if ($data['difficulty'] === 0) {
            $turnstile = $this->settings->turnstile();
            $response = $body['cf-turnstile-response'] ?? null;

            return $turnstile !== null && is_string($response) && $response !== '' && strlen($response) <= 2048
                && ($this->turnstileCheck)($turnstile['secret'], $response, $ip);
        }

        $solution = $body['s'] ?? null;

        return is_string($solution) && Tokens::solves((string) $body['c'], $solution, $data['difficulty']);
    }

    /** Back to the forum page asked for: always on this forum, whatever the path holds. */
    protected function back(string $return): string
    {
        $return = preg_replace('/[\x00-\x20\x7f\\\\]/', '', $return) ?? '/';

        return $this->url->to('forum')->base().'/'.ltrim($return, '/');
    }

    protected function askTurnstile(string $secret, string $response, string $ip): bool
    {
        try {
            $answer = (new Client(['timeout' => 8, 'connect_timeout' => 4]))->post(self::TURNSTILE_VERIFY, [
                'form_params' => ['secret' => $secret, 'response' => $response, 'remoteip' => $ip],
            ]);
            $json = json_decode((string) $answer->getBody(), true);

            return is_array($json) && ($json['success'] ?? false) === true;
        } catch (\Throwable) {
            return false;
        }
    }
}
