<?php

/*
 * This file is part of ernestdefoe/bouncer.
 */

namespace ErnestDefoe\Bouncer\Http;

use ErnestDefoe\Bouncer\Robots;
use ErnestDefoe\Bouncer\Settings;
use Flarum\Http\Exception\RouteNotFoundException;
use Laminas\Diactoros\Response\TextResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * /robots.txt and /ai.txt. Runs before Flarum resolves the route, so it
 * works whether or not another extension has a route for them: if one does,
 * Bouncer's group is added to its robots.txt (and its ai.txt is left
 * alone); if none does, Bouncer answers itself.
 */
class RobotsFiles implements MiddlewareInterface
{
    public function __construct(
        protected Settings $settings
    ) {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $path = Guard::path($request);
        $robots = $path === '/robots.txt' && $this->settings->robots();
        $ai = $path === '/ai.txt' && $this->settings->aiTxt();

        if (! ($robots || $ai) || ! in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return $handler->handle($request);
        }

        try {
            $response = $handler->handle($request);
        } catch (RouteNotFoundException) {
            $response = null;
        }

        $served = $response !== null && $response->getStatusCode() === 200;

        if ($ai) {
            return $served
                ? $response->withHeader('X-Bouncer', 'ai')
                : self::text(resolve(Robots::class)->aiTxt(), 'ai');
        }

        $existing = $served && str_starts_with($response->getHeaderLine('Content-Type'), 'text/plain') ? (string) $response->getBody() : null;

        return self::text(resolve(Robots::class)->robotsTxt($existing), 'robots');
    }

    protected static function text(string $body, string $which): Response
    {
        return new TextResponse($body, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'X-Bouncer' => $which,
        ]);
    }
}
