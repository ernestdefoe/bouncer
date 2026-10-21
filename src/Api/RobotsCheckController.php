<?php

/*
 * This file is part of ernestdefoe/bouncer.
 */

namespace ErnestDefoe\Bouncer\Api;

use Flarum\Http\RequestUtil;
use Flarum\Http\UrlGenerator;
use GuzzleHttp\Client;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Fetches the forum's own /robots.txt and ai.txt from outside, as a crawler
 * would, to show whether the web server hands those paths to Flarum (many
 * nginx setups answer them from disk). Admins only; the only address ever
 * fetched is the forum's own configured URL.
 */
class RobotsCheckController implements RequestHandlerInterface
{
    public function __construct(
        protected UrlGenerator $url
    ) {
    }

    public function handle(Request $request): Response
    {
        RequestUtil::getActor($request)->assertAdmin();

        $base = $this->url->to('forum')->base();
        $client = new Client(['timeout' => 8, 'connect_timeout' => 4, 'allow_redirects' => false, 'http_errors' => false]);
        $out = [];

        foreach (['robots' => '/robots.txt', 'ai' => '/ai.txt'] as $key => $path) {
            try {
                $response = $client->get($base.$path, ['headers' => ['User-Agent' => 'ernestdefoe-bouncer robots check']]);
                $body = substr((string) $response->getBody(), 0, 20000);
                $out[$key] = [
                    'status' => $response->getStatusCode(),
                    'bouncer' => $response->getHeaderLine('X-Bouncer') !== '',
                    'body' => $body,
                ];
            } catch (\Throwable $e) {
                $out[$key] = ['status' => 0, 'bouncer' => false, 'body' => '', 'error' => $e->getMessage()];
            }
        }

        return new JsonResponse($out);
    }
}
