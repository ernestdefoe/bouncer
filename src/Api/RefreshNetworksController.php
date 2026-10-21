<?php

/*
 * This file is part of ernestdefoe/bouncer.
 */

namespace ErnestDefoe\Bouncer\Api;

use ErnestDefoe\Bouncer\Network\Networks;
use ErnestDefoe\Bouncer\Network\Updater;
use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Cache\Repository as Cache;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Rebuilds the network lists now (after the admin changes which networks
 * count), instead of waiting for the weekly run. Admins only, once a minute.
 */
class RefreshNetworksController implements RequestHandlerInterface
{
    public function __construct(
        protected Updater $updater,
        protected Networks $networks,
        protected Cache $cache,
    ) {
    }

    public function handle(Request $request): Response
    {
        RequestUtil::getActor($request)->assertAdmin();

        if (! $this->cache->add('ernestdefoe-bouncer.refreshing', 1, 60)) {
            return new JsonResponse(['error' => 'busy'], 429);
        }

        @set_time_limit(180);

        try {
            $result = $this->updater->update();
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 502);
        }

        return new JsonResponse($result + ['networks' => $this->networks->info()]);
    }
}
