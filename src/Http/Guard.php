<?php

/*
 * This file is part of ernestdefoe/bouncer.
 */

namespace ErnestDefoe\Bouncer\Http;

use ErnestDefoe\Bouncer\ClientIp;
use ErnestDefoe\Bouncer\Inspector;
use ErnestDefoe\Bouncer\Log;
use ErnestDefoe\Bouncer\Settings;
use ErnestDefoe\Bouncer\Verdict;
use Flarum\Http\CookieFactory;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Response\TextResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * On the forum and API stacks, after authentication, before anything is
 * rendered: signed-in members, API tokens, internal calls and anything but
 * GET/HEAD go straight through untouched. Guests are inspected, and the
 * forum's pages and API either load, or a challenge, a refusal or a "slow
 * down" is answered instead, with no forum frontend built at all.
 */
class Guard implements MiddlewareInterface
{
    public const COOKIE = 'bouncer';

    /** Whether this instance guards the API (JSON answers) or the forum (pages). */
    protected bool $api = false;

    public function __construct(
        protected Settings $settings,
        protected Inspector $inspector,
        protected ClientIp $clientIp,
        protected Log $log,
        protected CookieFactory $cookies,
    ) {
    }

    public function process(Request $request, Handler $handler): Response
    {
        if (RequestUtil::isInternal($request) || ! in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return $handler->handle($request);
        }

        $path = self::path($request);

        if (! RequestUtil::getActor($request)->isGuest() || ($this->api && ! $this->settings->guardApi()) || $this->exempt($path)) {
            return $handler->handle($request);
        }

        $ip = $this->clientIp->resolve($request);
        $pass = $request->getCookieParams()[$this->cookies->getName(self::COOKIE)] ?? null;
        $verdict = $this->inspector->inspect($request, $ip, is_string($pass) ? $pass : null, $this->api);
        $monitor = $this->settings->monitorOnly();

        if ($verdict->log || $verdict->action !== Verdict::PASS) {
            $this->log->record($ip, $request->getHeaderLine('User-Agent'), $path, $verdict->logAction($monitor), $verdict->reason, $verdict->bot);
        }

        if ($verdict->action === Verdict::PASS || $monitor) {
            return $handler->handle($request);
        }

        return match ($verdict->action) {
            Verdict::CHALLENGE => $this->api
                ? $this->apiError(403, 'bouncer_challenge_required', 'Open the forum in a browser to continue.')
                : resolve(Challenge::class)->page($request, $ip, Challenge::returnPath($request)),
            Verdict::LIMIT => ($this->api
                ? $this->apiError(429, 'too_many_requests', 'Too many requests. Try again in a minute.')
                : self::text(429, "Too many requests. Try again in a minute.\n"))->withHeader('Retry-After', '60'),
            default => $this->api
                ? $this->apiError(403, 'bouncer_blocked', 'Access denied.')
                : self::text(403, $verdict->reason === 'bot'
                    ? "This forum doesn't allow this crawler. See /robots.txt.\n"
                    : "Access denied.\n"),
        };
    }

    /** The path below the forum's base URL, "/api/..." included. */
    public static function path(Request $request): string
    {
        $uri = $request->getAttribute('originalUri');

        return $uri instanceof UriInterface ? $uri->getPath() : $request->getUri()->getPath();
    }

    protected function exempt(string $path): bool
    {
        foreach ($this->settings->exemptPaths() as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    protected function apiError(int $status, string $code, string $detail): Response
    {
        return new JsonResponse(
            ['errors' => [['status' => (string) $status, 'code' => $code, 'detail' => $detail]]],
            $status,
            ['Content-Type' => 'application/vnd.api+json', 'Cache-Control' => 'no-store']
        );
    }

    protected static function text(int $status, string $body): Response
    {
        return new TextResponse($body, $status, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
